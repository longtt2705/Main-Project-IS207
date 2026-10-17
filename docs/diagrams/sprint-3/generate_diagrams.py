"""Rebuild the C5 diagrams as editable Draw.io/Mermaid and verified A4 artwork.

Run with Python + reportlab + pypdfium2. No network access is used by this script.
All diagram labels and edges are exported from the same semantic graph.
"""
from pathlib import Path
import csv
import html
import json
import math
import sys
import xml.etree.ElementTree as ET
import zipfile

from reportlab.pdfgen import canvas
from reportlab.lib.pagesizes import A4, landscape
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
import pypdfium2 as pdfium

ROOT = Path(__file__).resolve().parent
PROJECT = ROOT.parents[2]
PDF = PROJECT / 'output/pdf/sprint-3-diagrams.pdf'
ARCHIVE = PROJECT / 'output/sprint-3-diagrams.zip'
W, H = landscape(A4)
BLACK, BLUE, RED, WHITE = '#000000', '#174C65', '#B91C1C', '#FFFFFF'
FONT, BOLD = 'Arial', 'Arial-Bold'
pdfmetrics.registerFont(TTFont(FONT, 'C:/Windows/Fonts/arial.ttf'))
pdfmetrics.registerFont(TTFont(BOLD, 'C:/Windows/Fonts/arialbd.ttf'))


def node(id, text, x, y, w, h, lane='', shape='action'):
    return dict(id=id, text=text, x=x, y=y, w=w, h=h, lane=lane, shape=shape)


def edge(source, target, points, label='', pos=None, width=150, ex=None):
    return dict(source=source, target=target, points=points, label=label,
                pos=pos, width=width, ex=ex)


def activity(id, title, short, lanes, nodes, edges, notes):
    return dict(id=id, title=title, short=short, lanes=lanes, nodes=nodes,
                edges=edges, notes=notes, kind='activity')


diagrams = []
diagrams.append(activity('ACT-BKH-01', 'Đặt lịch khám tại bệnh viện', 'Đặt lịch bệnh viện',
    [('patient', 'Bệnh nhân', 72, 81), ('medsi', 'MedSi', 153, 244), ('pay', 'Miền PAY', 397, 77)], [
    node('start', '', 144, 104, 14, 14, 'patient', 'start'),
    node('select', 'Chọn viện, khoa,\nngày, ca và hồ sơ', 186, 88, 162, 46, 'patient'),
    node('check', 'Kiểm tra điều kiện\nvà sức chứa', 185, 181, 162, 44, 'medsi'),
    node('allowed', 'Được đặt?', 392, 171, 112, 60, 'medsi', 'decision'),
    node('hold', 'Giữ chỗ 15 phút', 564, 177, 164, 48, 'medsi'),
    node('pay', 'Thực hiện\nthanh toán', 594, 418, 185, 40, 'pay'),
    node('result', 'Kết quả / hạn?', 574, 283, 144, 66, 'medsi', 'decision'),
    node('confirm', 'Xác nhận lịch', 383, 293, 150, 44, 'medsi'),
    node('issue', 'Phát hành phiếu', 183, 293, 150, 44, 'medsi'),
    node('view', 'Xem phiếu và QR', 566, 88, 164, 46, 'patient'),
    node('end', '', 779, 104, 14, 14, 'patient', 'end'),
    node('release', 'Hủy lịch, nhả chỗ', 394, 354, 139, 38, 'medsi'),
    node('expired_end', '', 340, 366, 14, 14, 'medsi', 'end'),
], [
    edge('start', 'select', [(158,111),(186,111)]),
    edge('select', 'check', [(267,134),(267,181)]),
    edge('check', 'allowed', [(347,203),(392,201)]),
    edge('allowed', 'select', [(448,171),(448,77),(267,77),(267,88)], '[hết chỗ]', (449,108), 190, 'EX-BKH-01'),
    edge('allowed', 'select', [(392,201),(367,201),(367,148),(267,148),(267,134)], '[điều kiện sai]', (440,146), 145, 'EX-BKH-07'),
    edge('allowed', 'hold', [(448,231),(448,250),(646,250),(646,225)], '[hợp lệ, còn chỗ]', (551,243), 190),
    edge('hold', 'pay', [(728,201),(805,201),(805,438),(779,438)]),
    edge('pay', 'result', [(686,418),(686,370),(706,370),(706,343)]),
    edge('result', 'confirm', [(574,316),(553,316),(553,267),(458,267),(458,293)], '[đã trả, còn hạn]', (462,263), 180),
    edge('result', 'pay', [(718,316),(758,316),(758,418)], '[lỗi, còn hạn]', (760,364), 104, 'EX-BKH-08'),
    edge('result', 'release', [(646,349),(646,373),(533,373)], '[hết 15 phút]', (590,382), 110, 'EX-BKH-02'),
    edge('release', 'expired_end', [(394,373),(354,373)]),
    edge('confirm', 'issue', [(383,315),(333,315)]),
    edge('issue', 'view', [(183,315),(134,315),(134,141),(550,141),(550,111),(566,111)]),
    edge('view', 'end', [(730,111),(779,111)]),
], ['Khóa và giữ chỗ phải nguyên tử; cùng khoa/ngày/ca không vượt sức chứa.',
    'Kiểm tra ngày 1-30, giới hạn 3 lịch và trùng hồ sơ/khoa/ngày (BR-BKH-01/05/06).',
    'PENDING_PAYMENT → CONFIRMED; chỉ lúc đó cấp ISSUED. Phí: 200.000đ + 20.000đ.']))

diagrams.append(activity('ACT-BKH-02', 'Check-in và giới hạn trách nhiệm MedSi', 'Tiếp đón tại viện',
    [('patient','Bệnh nhân',72,79),('staff','Kiosk /\nTiếp đón',151,122),('medsi','MedSi',273,201)], [
    node('start','',144,104,14,14,'patient','start'),
    node('present','Xuất trình QR',186,88,150,46,'patient'),
    node('scan','Quét và gửi QR',184,176,150,42,'staff'),
    node('valid','QR hợp lệ?',187,293,142,70,'medsi','decision'),
    node('date','Đúng ngày?',387,293,125,70,'medsi','decision'),
    node('state','Tiếp nhận\nđược?',586,293,145,70,'medsi','decision'),
    node('mark','Ghi CHECKED_IN\nvà check_in_at',589,406,160,42,'medsi'),
    node('reply','Hiển thị STT, phòng',588,222,160,40,'staff'),
    node('clinic','Đến phòng khám\n(ngoài MedSi)',626,88,150,46,'patient'),
    node('end','',797,104,14,14,'patient','end'),
    node('invalid','Từ chối mã QR',365,172,162,38,'staff'),
    node('wrong_day','Báo sai ngày',585,172,162,38,'staff'),
    node('used','Báo trạng thái\nGiữ nguyên phiếu',365,406,164,42,'medsi'),
    node('help','Liên hệ tiếp đón',365,88,164,46,'patient'),
    node('help_end','',550,104,14,14,'patient','end'),
], [
    edge('start','present',[(158,111),(186,111)]),
    edge('present','scan',[(261,134),(261,176)]),
    edge('scan','valid',[(259,218),(259,293)]),
    edge('valid','date',[(329,328),(387,328)],'[hợp lệ]',(358,314),85),
    edge('valid','invalid',[(258,293),(258,272),(351,272),(351,191),(365,191)],'[sai / hết hạn]',(306,252),145,'EX-BKH-03'),
    edge('invalid','help',[(446,172),(446,134)]),
    edge('date','state',[(512,328),(586,328)],'[đúng ngày]',(549,314),98),
    edge('date','wrong_day',[(449,293),(449,282),(565,282),(565,191),(585,191)],'[sai ngày]',(498,253),130,'EX-BKH-04'),
    edge('wrong_day','help',[(666,172),(666,145),(447,145),(447,134)]),
    edge('state','mark',[(658,363),(658,406)],'[ISSUED]',(658,384),90),
    edge('state','used',[(586,328),(562,328),(562,427),(529,427)],'[đã dùng / hủy]',(531,378),135,'EX-BKH-09'),
    edge('used','help',[(365,427),(344,427),(344,111),(365,111)]),
    edge('help','help_end',[(529,111),(550,111)]),
    edge('mark','reply',[(749,427),(792,427),(792,242),(748,242)]),
    edge('reply','clinic',[(588,242),(550,242),(550,145),(701,145),(701,134)]),
    edge('clinic','end',[(776,111),(797,111)]),
], ['Chỉ tiếp đón được phân quyền tại đúng viện; kiểm tra chữ ký và trạng thái trong DB.',
    'Quét lại không đổi trạng thái, không cấp STT mới (BR-TKT-07/08/09).',
    'MedSi dừng ở check-in; khám, xét nghiệm, kê đơn thuộc viện. OQ-BKH-06 còn Open.']))

diagrams.append(activity('ACT-BKH-03','Xem và tra cứu lịch sử đặt khám','Tra cứu lịch sử',
    [('patient','Bệnh nhân',72,110),('medsi','MedSi',182,292)], [
    node('start','',144,104,14,14,'patient','start'),
    node('open','Mở lịch sử',186,88,150,42,'patient'),
    node('login','Đăng nhập lại',186,143,150,34,'patient'),
    node('auth','Phiên hợp lệ?',190,215,140,64,'medsi','decision'),
    node('filters','Lọc / tìm mã phiếu\nChọn trang',395,88,170,46,'patient'),
    node('query','Lấy lịch của mình\nvà phân trang',395,226,170,44,'medsi'),
    node('found','Có kết quả?',631,215,130,64,'medsi','decision'),
    node('list','Hiển thị danh sách',633,315,154,44,'medsi'),
    node('empty','Báo không có lịch',580,143,180,34,'patient'),
    node('select','Chọn lịch cần xem',620,143,170,34,'patient'),
    node('owner','Đúng chủ sở hữu?',392,310,142,64,'medsi','decision'),
    node('ticket','Có phiếu?',192,310,140,64,'medsi','decision'),
    node('display','Xem QR / tải phiếu',620,88,170,46,'patient'),
    node('end','',801,104,14,14,'patient','end'),
    node('deny','Từ chối truy cập',392,407,171,40,'medsi'),
    node('deny_end','',579,420,14,14,'medsi','end'),
    node('status','Xem trạng thái\nChưa phát hành',186,407,148,40,'medsi'),
    node('status_end','',354,420,14,14,'medsi','end'),
], [
    edge('start','open',[(158,111),(186,109)]),
    edge('open','auth',[(336,109),(355,109),(355,198),(260,198),(260,215)]),
    edge('auth','login',[(190,247),(144,247),(144,160),(186,160)],'[hết phiên]',(175,292),110,'EX-BKH-06'),
    edge('login','open',[(186,160),(170,160),(170,109),(186,109)]),
    edge('auth','filters',[(330,247),(376,247),(376,111),(395,111)],'[hợp lệ]',(369,208),85),
    edge('filters','query',[(480,134),(480,226)]),
    edge('query','found',[(565,248),(631,247)]),
    edge('found','list',[(696,279),(710,315)],'[có]',(710,296),55),
    edge('found','empty',[(761,247),(806,247),(806,193),(810,193),(810,165),(760,165)],'[không có]',(764,285),100,'EX-BKH-05'),
    edge('empty','filters',[(580,160),(575,160),(575,111),(565,111)]),
    edge('list','select',[(633,336),(608,336),(608,190),(705,190),(705,177)]),
    edge('select','owner',[(620,160),(588,160),(588,300),(463,300),(463,310)]),
    edge('owner','ticket',[(392,342),(365,342),(365,389),(262,389),(262,374)],'[thuộc mình]',(315,390),115),
    edge('owner','deny',[(463,374),(463,407)],'[không thuộc mình]',(545,390),160,'EX-BKH-10'),
    edge('deny','deny_end',[(563,427),(579,427)]),
    edge('ticket','status',[(262,374),(262,407)],'[chưa có]',(219,390),90),
    edge('status','status_end',[(334,427),(354,427)]),
    edge('ticket','display',[(192,342),(166,342),(166,69),(705,69),(705,88)],'[đã phát hành]',(430,68),150),
    edge('display','end',[(790,111),(801,111)]),
], ['Mọi truy vấn lọc theo user_account hiện tại; kiểm tra lại khi mở chi tiết.',
    'Nhóm lịch: sắp khám / đã khám / đã hủy hoặc vắng mặt. Tra cứu bằng ticket_code.',
    'Phiếu có STT, dặn dò; QR hủy/hết hạn không dùng check-in. Tải PNG hoặc in/PDF.']))
# Separate the empty-result action from the selected-record action.
diagrams[2]['nodes'] = [dict(n, x=580, y=185, w=180, h=28, lane='medsi') if n['id']=='empty' else n for n in diagrams[2]['nodes']]
for e in diagrams[2]['edges']:
    if e['target']=='empty': e['points']=[(761,247),(806,247),(806,199),(760,199)]
    if e['source']=='empty': e['points']=[(580,199),(575,199),(575,111),(565,111)]

diagrams.append(dict(id='STM-TKT-01', title='Vòng đời phiếu và trạng thái lịch khám',
    short='Vòng đời phiếu khám', lanes=[], kind='state', notes=[
        'Không tạo phiếu trước CONFIRMED; mỗi appointment chỉ có một ticket.',
        'CANCELLED, EXPIRED, COMPLETED giữ bản ghi; không cấp lại số thứ tự đã hủy.',
        'Mốc +120 phút và xác nhận hoàn tất cần chốt với chủ miền; xem bảng kèm theo.'], nodes=[
        node('start','',44,243,14,14,shape='start'),
        node('ISSUED','ISSUED\nĐã phát hành',195,215,165,70,shape='state'),
        node('CHECKED_IN','CHECKED_IN\nĐã tiếp nhận',555,215,165,70,shape='state'),
        node('COMPLETED','COMPLETED\nĐã khám xong',555,405,165,60,shape='state'),
        node('CANCELLED','CANCELLED\nĐã hủy phiếu',195,405,165,60,shape='state'),
        node('EXPIRED','EXPIRED\nHết hiệu lực',195,75,165,70,shape='state'),
    ], edges=[
        edge('start','ISSUED',[(58,250),(195,250)],'Xác nhận lịch\n[CONFIRMED, đã trả]\n/ Tạo phiếu', (119,308), 172),
        edge('ISSUED','CHECKED_IN',[(360,250),(555,250)],'Quét QR [đúng phiếu,\nđúng ngày, chưa dùng]\n/ Ghi CHECKED_IN',(457,226),190),
        edge('CHECKED_IN','COMPLETED',[(637,285),(637,405)],'Xác nhận đã khám\n[đã check-in]\n/ Lịch = COMPLETED',(638,345),260),
        edge('ISSUED','CANCELLED',[(277,285),(277,405)],'Hủy lịch [hợp lệ]\n/ Vô hiệu QR; gọi PAY',(277,367),260),
        edge('ISSUED','EXPIRED',[(277,215),(277,145)],'Kiểm tra hạn [ca +120p]\n/ Lịch = NO_SHOW',(155,181),250,'EX-TKT-03'),
        edge('ISSUED','ISSUED',[(325,285),(325,313),(430,313),(430,196),(330,196),(330,215)],'Quét QR [sai/ngày sai]\n/ Giữ nguyên phiếu',(461,115),180,'EX-TKT-01'),
        edge('CHECKED_IN','CHECKED_IN',[(720,250),(782,250),(782,180),(637,180),(637,215)],'Quét lại [đã check-in]\n/ Báo giờ tiếp nhận',(675,115),250,'EX-TKT-02'),
    ]))

# Layout refinements and explicit UML merge nodes for alternative incoming flows.
def revise(d, source, old_target, **values):
    matches=[e for e in d['edges'] if e['source']==source and e['target']==old_target]
    assert len(matches)==1, (source,old_target)
    matches[0].update(values)

d=diagrams[0]
d['nodes'] += [node('choice_merge','',168,105,12,12,'patient','merge'),node('pay_merge','',752,400,12,12,'pay','merge')]
revise(d,'start','select',target='choice_merge',points=[(158,111),(168,111)])
d['edges'].append(edge('choice_merge','select',[(180,111),(186,111)]))
# Distinguish the two branches that return to the same selection merge.
for e in d['edges']:
    if e['ex']=='EX-BKH-01':e.update(target='choice_merge',points=[(448,171),(448,164),(530,164),(530,77),(174,77),(174,105)],pos=(454,94))
    if e['ex']=='EX-BKH-07':e.update(target='choice_merge',points=[(504,201),(542,201),(542,142),(174,142),(174,117)],pos=(420,135))
revise(d,'allowed','hold',pos=(551,237))
revise(d,'result','confirm',points=[(574,316),(553,316),(553,270),(458,270),(458,293)],pos=(462,260))
revise(d,'issue','view',points=[(183,315),(134,315),(134,165),(550,165),(550,111),(566,111)])
revise(d,'hold','pay',target='pay_merge',points=[(728,201),(805,201),(805,406),(764,406)])
revise(d,'pay','result',points=[(686,418),(686,370),(682,370),(682,332.5)])
revise(d,'result','pay',target='pay_merge',points=[(718,316),(758,316),(758,400)],pos=(760,363))
d['edges'].append(edge('pay_merge','pay',[(758,412),(758,418)]))
revise(d,'result','release',points=[(646,349),(646,392),(543,392),(543,373),(533,373)],pos=(591,369),width=100)

d=diagrams[1]
d['nodes'] += [node('help_merge','',441,142,12,12,'patient','merge'),node('used_end','',303,420,14,14,'medsi','end')]
revise(d,'scan','valid',points=[(259,218),(259,226),(177,226),(177,328),(187,328)])
revise(d,'state','used',points=[(658,293),(658,281),(773,281),(773,397),(548,397),(548,427),(529,427)],pos=(530,376))
revise(d,'used','help',target='used_end',points=[(365,427),(317,427)],ex='EX-BKH-09')
revise(d,'invalid','help',target='help_merge',points=[(446,172),(447,154)],ex='EX-BKH-03')
revise(d,'wrong_day','help',target='help_merge',points=[(666,172),(666,148),(453,148)],ex='EX-BKH-04')
d['edges'].append(edge('help_merge','help',[(447,142),(447,134)]))

d=diagrams[2]
d['nodes'] += [node('open_merge','',168,105,12,12,'patient','merge'),node('filter_merge','',377,105,12,12,'patient','merge')]
revise(d,'start','open',target='open_merge',points=[(158,111),(168,111)])
d['edges'].append(edge('open_merge','open',[(180,111),(186,109)]))
revise(d,'login','open',target='open_merge',points=[(261,143),(261,138),(174,138),(174,117)],ex='EX-BKH-06')
revise(d,'auth','filters',target='filter_merge',points=[(330,247),(365,247),(365,125),(383,125),(383,117)],pos=(371,208))
d['edges'].append(edge('filter_merge','filters',[(389,111),(395,111)]))
revise(d,'empty','filters',target='filter_merge',points=[(580,199),(578,199),(578,80),(383,80),(383,105)],ex='EX-BKH-05')
revise(d,'list','select',points=[(787,336),(800,336),(800,179),(705,179),(705,177)])
revise(d,'select','owner',points=[(620,160),(570,160),(570,300),(463,300),(463,310)])
revise(d,'found','empty',pos=(772,287),width=90)
revise(d,'found','list',points=[(696,279),(696,300),(710,300),(710,315)],pos=(696,293),width=45)
revise(d,'owner','ticket',points=[(392,342),(360,342),(360,292),(262,292),(262,310)],pos=(311,292),width=110)
revise(d,'ticket','display',label='[đã có]',pos=(188,365),width=65)
revise(d,'auth','login',pos=(172,290),width=98)

d=diagrams[3]
revise(d,'ISSUED','ISSUED',points=[(360,230),(480,230),(480,155),(330,155),(330,215)],pos=(468,115),width=185)
revise(d,'CHECKED_IN','CHECKED_IN',pos=(674,168),width=240)
revise(d,'ISSUED','CHECKED_IN',pos=(457,282),width=185)
revise(d,'ISSUED','EXPIRED',label='Kiểm tra hạn\n[quá cuối ca +120p]\n/ Lịch = NO_SHOW',pos=(140,181),width=235)


def wrap(text, width, font=FONT, size=12):
    lines=[]
    for paragraph in text.split('\n'):
        line=''
        for word in paragraph.split(' '):
            proposed=(line+' '+word).strip()
            if pdfmetrics.stringWidth(proposed,font,size)>width and line:
                lines.append(line);line=word
            else: line=proposed
        lines.append(line)
    return lines


def draw_text(c, text, x, y, w, h, size=12, bold=False, color=BLACK, align='center', background=False):
    font=BOLD if bold else FONT
    lines=wrap(text,w-8,font,size)
    leading=size*1.17
    if len(lines)*leading>h+1:
        raise ValueError(f'Text exceeds box ({len(lines)*leading:.1f} > {h}): {text}')
    if background:
        ink_width=max(pdfmetrics.stringWidth(s,font,size) for s in lines)+6
        c.setFillColor(WHITE);c.rect(x+(w-ink_width)/2,y_to_pdf(y+h),ink_width,h,stroke=0,fill=1)
    c.setFont(font,size);c.setFillColor(color)
    top=y+(h-len(lines)*leading)/2
    for i,line in enumerate(lines):
        baseline=H-(top+i*leading+size)
        if align=='center':c.drawCentredString(x+w/2,baseline,line)
        else:c.drawString(x+4,baseline,line)


def y_to_pdf(y):return H-y


def line(c, points, color=BLACK, dashed=False, arrow=True, halo=False):
    def path():
        p=c.beginPath();p.moveTo(points[0][0],H-points[0][1])
        for x,y in points[1:]:p.lineTo(x,H-y)
        return p
    if halo:
        c.setStrokeColor(WHITE);c.setLineWidth(4);c.setDash();c.drawPath(path())
    c.setStrokeColor(color);c.setFillColor(color);c.setLineWidth(1.2)
    c.setDash(5,3) if dashed else c.setDash()
    c.drawPath(path());c.setDash()
    if arrow:
        x,y=points[-1];px,py=points[-2];theta=math.atan2(y-py,x-px)
        p=c.beginPath();p.moveTo(x,H-y)
        for delta in [-.48,.48]:p.lineTo(x-7*math.cos(theta+delta),H-(y-7*math.sin(theta+delta)))
        p.close();c.drawPath(p,stroke=0,fill=1)


def node_pdf(c,n):
    x,y,w,h=n['x'],n['y'],n['w'],n['h'];shape=n['shape']
    c.setStrokeColor(BLACK);c.setFillColor(WHITE);c.setLineWidth(1.2)
    if shape in ['start','end']:
        c.circle(x+w/2,H-y-h/2,w/2,stroke=1,fill=0)
        c.setFillColor(BLACK);c.circle(x+w/2,H-y-h/2,w/2-(3 if shape=='end' else 0),stroke=0,fill=1)
    elif shape in ['decision','merge']:
        p=c.beginPath();p.moveTo(x+w/2,H-y)
        for a,b in [(x+w,y+h/2),(x+w/2,y+h),(x,y+h/2)]:p.lineTo(a,H-b)
        p.close();c.drawPath(p,stroke=1,fill=1)
        if n['text']:draw_text(c,n['text'],x+w*.12,y+h*.15,w*.76,h*.7)
    else:
        c.roundRect(x,H-y-h,w,h,7,stroke=1,fill=1)
        draw_text(c,n['text'],x+3,y+2,w-6,h-4,bold=shape=='state')


def footer(c,d):
    line(c,[(24,488),(60,488)],arrow=True);draw_text(c,'Luồng chính',66,477,130,24,align='left')
    line(c,[(225,488),(261,488)],RED,True);draw_text(c,'Ngoại lệ (EX)',267,477,150,24,align='left',color=RED)
    draw_text(c,f"{len(d['nodes'])} nút | A4 ngang",419,477,145,24,align='left')
    draw_text(c,'\n'.join(d['notes']),24,507,524,75,align='left')
    c.setStrokeColor(BLUE);c.setLineWidth(1);c.rect(568,H-583,250,83,stroke=1,fill=0)
    meta=f"{d['short']}\nMã: {d['id']} | v2.0\nTác giả tái dựng: Codex\nChủ miền: Tuấn Anh | 17/10/2026"
    draw_text(c,meta,573,505,240,73,align='left')


def draw_diagram(c,d):
    c.setFillColor(WHITE);c.rect(0,0,W,H,stroke=0,fill=1)
    draw_text(c,d['title'],24,18,794,28,18,True,BLUE,align='left')
    draw_text(c,f"{d['id']} | Đặc tả C5 - MedSi | GMT+7",24,47,794,18,12,False,align='left')
    for id,label,y,height in d['lanes']:
        c.setStrokeColor(BLACK);c.setFillColor(WHITE);c.setLineWidth(.7)
        c.rect(24,H-y-height,794,height,stroke=1,fill=1)
        c.setFillColor(BLUE);c.rect(24,H-y-height,100,height,stroke=0,fill=1)
        draw_text(c,label,27,y+5,94,height-10,12,True,WHITE)
    for e in d['edges']:line(c,e['points'],RED if e['ex'] else BLACK,bool(e['ex']),halo=True)
    for n in d['nodes']:node_pdf(c,n)
    for e in d['edges']:
        if e['label']:
            label=e['label']+('\n'+e['ex'] if e['ex'] else '')
            height=len(wrap(label,e['width']-8))*14.04+4
            x,y=e['pos'];draw_text(c,label,x-e['width']/2,y-height/2,e['width'],height,color=RED if e['ex'] else BLACK,background=True)
    footer(c,d);c.showPage()


def mermaid(d):
    # Mermaid supports state-machine semantics in flowchart notation with styled
    # states. This keeps red exception edges and explicit UML event/guard/action.
    lines=['%% A2: paste this source into a Mermaid block in Notion.',
           '%% The companion Draw.io/PDF retains the manual A4 print layout.',
           '%% All graph nodes and transitions match the companion exports.',
           '%%{init: {"theme":"base","themeVariables":{"fontFamily":"Arial","fontSize":"16px","primaryColor":"#ffffff","primaryTextColor":"#000000","primaryBorderColor":"#000000","lineColor":"#000000","clusterBkg":"#ffffff","clusterBorder":"#174C65"},"flowchart":{"curve":"linear","htmlLabels":true}}}%%',
           'flowchart LR']
    def emit(n):
        label=n['text'].replace('\n','<br/>').replace('"','&quot;')
        if n['shape']=='start':return f'  n_{n["id"]}(("●"))'
        if n['shape']=='end':return f'  n_{n["id"]}(("◉"))'
        if n['shape']=='decision':return f'  n_{n["id"]}{{"{label}"}}'
        if n['shape']=='merge':return f'  n_{n["id"]}{{" "}}'
        return f'  n_{n["id"]}("{label}")'
    if d['lanes']:
        for id,label,*_ in d['lanes']:
            lines += [f' subgraph lane_{id}["{label.replace(chr(10)," / ")}"]',' direction TB']
            lines += [emit(n) for n in d['nodes'] if n['lane']==id]
            lines += [' end']
    else: lines += [emit(n) for n in d['nodes']]
    for i,e in enumerate(d['edges']):
        label=e['label'].replace('\n','<br/>')+('<br/>'+e['ex'] if e['ex'] and e['label'] else '')
        arrow='-.->' if e['ex'] else '-->'
        lines.append(f' n_{e["source"]} {arrow}'+(f'|"{label}"|' if label else '')+f' n_{e["target"]}')
    for i,e in enumerate(d['edges']):
        if e['ex']:lines.append(f' linkStyle {i} stroke:#B91C1C,color:#B91C1C,stroke-width:1.6px,stroke-dasharray:5 3;')
    lines += ['%% Metadata: '+d['id']+' | '+d['short']+' | Codex (tái dựng); Tuấn Anh (chủ miền) | 17/10/2026 | v2.0']
    return '\n'.join(lines)+'\n'


def mermaid_state(d):
    # Mermaid 11.12 state diagrams do not implement flowchart linkStyle.
    # Style the generated transition IDs; the styled companion is a fallback
    # for hosts such as Notion that strip init/themeCSS configuration.
    css = ('path#edge4,path[id^="ISSUED-cyclic"],path[id^="CHECKED_IN-cyclic"]'
        '{stroke:#B91C1C!important;stroke-dasharray:5 3!important;}'
        '[data-id="edge4"] span,[data-id^="ISSUED-cyclic"] span,[data-id^="CHECKED_IN-cyclic"] span'
        '{color:#B91C1C!important;}')
    config={'theme':'base','look':'classic','themeVariables':{
        'fontFamily':'Arial','fontSize':'16px','primaryColor':WHITE,'primaryTextColor':BLACK,
        'primaryBorderColor':BLACK,'lineColor':BLACK,'noteBkgColor':WHITE,
        'noteBorderColor':BLUE,'noteTextColor':BLACK},'themeCSS':css}
    lines = ['%% Native Mermaid state machine; see the PDF/Draw.io for the A4 layout.',
        '%%{init: '+json.dumps(config)+'}%%',
        'stateDiagram-v2', '    direction LR']
    for n in d['nodes']:
        if n['shape']=='state':
            label=n['text'].replace('\n','<br/>')
            lines.append(f'    state "{label}" as {n["id"]}')
    for e in d['edges']:
        source='[*]' if e['source']=='start' else e['source']
        label=e['label'].replace('\n',' ')
        if e['ex']:label+=' ('+e['ex']+')'
        lines.append(f'    {source} --> {e["target"]}: {label}')
    lines += ['    note right of EXPIRED',
              '        Mốc cuối ca +120 phút còn cần chốt.',
              '        BR-BKH-12 / TKT_STM khác cách ghi BR-TKT-10.',
              '    end note',
              '%% Codex (tái dựng); Tuấn Anh (chủ miền) | 17/10/2026 | v2.0']
    return '\n'.join(lines)+'\n'


def drawio(d):
    scale=4/3
    mx=ET.Element('mxfile',host='app.diagrams.net',modified='2026-10-17T00:00:00+07:00',type='device')
    diagram=ET.SubElement(mx,'diagram',id=d['id'],name=d['id'])
    model=ET.SubElement(diagram,'mxGraphModel',dx='1122',dy='794',grid='1',gridSize='10',page='1',pageScale='1',pageWidth='1122',pageHeight='794',background=WHITE)
    root=ET.SubElement(model,'root');ET.SubElement(root,'mxCell',id='0');ET.SubElement(root,'mxCell',id='1',parent='0')
    def vertex(id,label,x,y,w,h,style):
        cell=ET.SubElement(root,'mxCell',id=id,value=html.escape(label).replace('\n','<br>'),style='html=1;whiteSpace=wrap;fontFamily=Arial;fontSize=16;fontColor=#000000;'+style,vertex='1',parent='1')
        ET.SubElement(cell,'mxGeometry',x=str(x*scale),y=str(y*scale),width=str(w*scale),height=str(h*scale),attrib={'as':'geometry'})
    for id,label,y,height in d['lanes']:
        vertex('lane_'+id,'',24,y,794,height,'fillColor=#ffffff;strokeColor=#000000;')
        vertex('label_'+id,label,24,y,100,height,'fillColor=#174C65;strokeColor=#174C65;fontColor=#ffffff;fontStyle=1;')
    vertex('title',d['title'],24,18,794,28,'text;strokeColor=none;fillColor=none;fontSize=24;fontStyle=1;fontColor=#174C65;align=left;')
    vertex('subtitle',f"{d['id']} | Đặc tả C5 - MedSi | GMT+7",24,47,794,18,'text;strokeColor=none;fillColor=none;align=left;')
    by_id={n['id']:n for n in d['nodes']}
    for n in d['nodes']:
        styles={'action':'rounded=1;arcSize=15;','decision':'shape=rhombus;perimeter=rhombusPerimeter;','merge':'shape=rhombus;perimeter=rhombusPerimeter;','start':'shape=ellipse;fillColor=#000000;','end':'shape=ellipse;fillColor=#ffffff;','state':'rounded=1;arcSize=15;fontStyle=1;'}
        vertex(n['id'],n['text'],n['x'],n['y'],n['w'],n['h'],'fillColor=#ffffff;strokeColor=#000000;strokeWidth=1.6;'+styles[n['shape']])
        if n['shape']=='end':
            vertex('end_dot_'+n['id'],'',n['x']+3,n['y']+3,n['w']-6,n['h']-6,'shape=ellipse;fillColor=#000000;strokeColor=none;')
    labels=[]
    for i,e in enumerate(d['edges']):
        a,b=by_id[e['source']],by_id[e['target']];first,last=e['points'][0],e['points'][-1]
        style=f"edgeStyle=none;rounded=0;html=1;endArrow=block;endFill=1;strokeWidth=1.6;strokeColor={RED if e['ex'] else BLACK};dashed={int(bool(e['ex']))};"
        style+=f"exitX={(first[0]-a['x'])/a['w']};exitY={(first[1]-a['y'])/a['h']};exitPerimeter=0;entryX={(last[0]-b['x'])/b['w']};entryY={(last[1]-b['y'])/b['h']};entryPerimeter=0;"
        cell=ET.SubElement(root,'mxCell',id='edge_'+str(i),style=style,edge='1',parent='1',source=e['source'],target=e['target'])
        geometry=ET.SubElement(cell,'mxGeometry',relative='1',attrib={'as':'geometry'})
        arr=ET.SubElement(geometry,'Array',attrib={'as':'points'})
        for x,y in e['points'][1:-1]:ET.SubElement(arr,'mxPoint',x=str(x*scale),y=str(y*scale))
        if e['label']:
            label=e['label']+('\n'+e['ex'] if e['ex'] else '')
            lines=wrap(label,e['width']-8)
            height=len(lines)*14.04+4;x,y=e['pos']
            ink_width=max(pdfmetrics.stringWidth(s,FONT,12) for s in lines)+8
            labels.append(('edge_label_'+str(i),'\n'.join(lines),x-ink_width/2,y-height/2,ink_width,height,'shape=rectangle;strokeColor=none;fillColor=#ffffff;spacing=0;fontColor='+ (RED if e['ex'] else BLACK)+';'))
    for args in labels:vertex(*args)
    vertex('legend','→ Luồng chính     - - → Ngoại lệ (EX)     '+str(len(d['nodes']))+' nút',24,477,535,24,'text;strokeColor=none;fillColor=none;align=left;')
    vertex('notes','\n'.join(d['notes']),24,507,524,75,'text;strokeColor=none;fillColor=none;align=left;')
    vertex('metadata',f"{d['short']}\nMã: {d['id']} | v2.0\nTác giả tái dựng: Codex\nChủ miền: Tuấn Anh | 17/10/2026",568,500,250,83,'fillColor=#ffffff;strokeColor=#174C65;align=left;spacing=6;')
    ET.indent(mx);return ET.tostring(mx,encoding='unicode',xml_declaration=True)


TRANSITIONS = [
 ['Chưa có phiếu','ISSUED','Xác nhận lịch','MedSi / PAY','appointment = CONFIRMED; thanh toán đã xác nhận; chưa có ticket.','Tạo đúng một ticket; ký QR; cấp STT theo viện/khoa/ngày. Callback lặp trả phiếu cũ.'],
 ['ISSUED','CHECKED_IN','Quét QR','Kiosk / tiếp đón','Được phân quyền đúng viện; QR đúng chữ ký, đúng ngày, chưa hết hạn; lịch CONFIRMED.','Ghi check_in_at một lần; lịch → CHECKED_IN; trả STT/phòng. Không tự giả định đồng bộ HIS.'],
 ['CHECKED_IN','COMPLETED','Xác nhận đã khám','Nhân viên được quyền; HIS nếu đã tích hợp','Đã check-in; có xác nhận hoàn tất từ cơ sở.','Lịch → COMPLETED; chuyển sang nhóm đã khám. Tín hiệu HIS là tích hợp còn cần chốt.'],
 ['ISSUED','CANCELLED','Hủy lịch','Bệnh nhân / viện','Bệnh nhân: trước ca ít nhất 24h; hoặc viện chủ động hủy.','Lịch → CANCELLED; vô hiệu QR, nhả chỗ, không tái dùng STT; yêu cầu PAY hoàn phí theo chính sách.'],
 ['ISSUED','EXPIRED','Kiểm tra hạn','Bộ định thời MedSi','Vẫn ISSUED; quá kết thúc ca +120 phút (giả định cần chốt).','Lịch → NO_SHOW; vô hiệu QR; không tự hoàn tiền. BR-TKT-10 và BR-BKH-12 chưa cùng cách diễn đạt.'],
 ['ISSUED','ISSUED','Quét QR lỗi\nEX-TKT-01','Kiosk / tiếp đón','Chữ ký/token sai, sai viện hoặc sai ngày.','Không chuyển trạng thái, không ghi check_in_at; báo lỗi. Mã giả không tồn tại không tạo ticket mới.'],
 ['CHECKED_IN','CHECKED_IN','Quét lặp\nEX-TKT-02','Kiosk / tiếp đón','Phiếu đã được tiếp nhận.','Giữ nguyên check_in_at; báo thời điểm quét trước; không cấp lại STT.'],
 ['CANCELLED / EXPIRED / COMPLETED','Không đổi','Quét phiếu kết thúc','Kiosk / tiếp đón','Phiếu đã hủy, hết hạn hoặc hoàn tất.','Từ chối check-in; giữ bản ghi lịch sử và trạng thái lịch khám tương ứng.'],
]


def table_page(c, rows, number):
    draw_text(c,'Bảng chuyển trạng thái phiếu khám',24,18,794,28,18,True,BLUE,align='left')
    draw_text(c,f'STM-TKT-01 | Phần {number}/2 | A4.7: từ, đến, sự kiện, actor, điều kiện, tác dụng phụ',24,49,794,22,align='left')
    widths=[100,100,112,110,164,208];headers=['Từ','Đến','Sự kiện','Ai kích hoạt','Điều kiện','Tác dụng phụ']
    y=82
    for row_id,row in enumerate([headers]+rows):
        font=BOLD if row_id==0 else FONT
        heights=[len(wrap(v,w-12,font))*14.04+16 for v,w in zip(row,widths)]
        height=max(heights);x=24
        for value,w in zip(row,widths):
            c.setFillColor(BLUE if row_id==0 else WHITE);c.setStrokeColor(BLACK);c.setLineWidth(.6)
            c.rect(x,H-y-height,w,height,stroke=1,fill=1)
            draw_text(c,value,x+2,y+4,w-4,height-8,bold=row_id==0,color=WHITE if row_id==0 else BLACK,align='left')
            x+=w
        y+=height
    if y>490:raise ValueError('Transition table too tall: '+str(y))
    note='Hủy bởi bệnh nhân: yêu cầu hoàn 200.000đ phí khám. Viện hủy: yêu cầu hoàn 220.000đ. PAY xử lý hoàn tiền; không đồng nhất yêu cầu hoàn với tiền đã hoàn.' if number==1 else 'Các trạng thái giữ nguyên bộ tên gốc. Cần xác nhận với chủ miền/An: mốc hết hạn (+120 phút) và nguồn xác nhận COMPLETED. Không ghi các quyết định này là đã được nhóm phê duyệt.'
    draw_text(c,note,24,497,794,58,align='left')
    draw_text(c,'Nguồn: PDF A1/A4/A5/A10/C5; BKH_BR, TKT_BR; TKT_STM. Bản tái dựng 17/10/2026.',24,563,794,20,align='left')
    c.showPage()


def build():
    ROOT.mkdir(parents=True,exist_ok=True);PDF.parent.mkdir(parents=True,exist_ok=True)
    for d in diagrams:
        assert len(d['nodes'])<=25
        assert len({e['ex'] for e in d['edges'] if e['ex']})>=2
        ids={n['id'] for n in d['nodes']}
        assert all(e['source'] in ids and e['target'] in ids for e in d['edges'])
        by_id={n['id']:n for n in d['nodes']}
        for e in d['edges']:
            for id,(x,y) in [(e['source'],e['points'][0]),(e['target'],e['points'][-1])]:
                n=by_id[id];cx=n['x']+n['w']/2;cy=n['y']+n['h']/2
                dx=abs(x-cx)/(n['w']/2);dy=abs(y-cy)/(n['h']/2)
                distance=dx+dy if n['shape'] in ['decision','merge'] else max(dx,dy)
                assert abs(distance-1)<.1, (d['id'],id,'disconnected endpoint',x,y)
        for n in d['nodes']:
            if n['shape']=='decision':assert all(e['label'].startswith('[') for e in d['edges'] if e['source']==n['id'])
        (ROOT/(d['id']+'.mmd')).write_text(mermaid_state(d) if d['kind']=='state' else mermaid(d),encoding='utf-8')
        if d['kind']=='state':
            (ROOT/(d['id']+'-styled.mmd')).write_text(mermaid(d),encoding='utf-8')
        (ROOT/(d['id']+'.drawio')).write_text(drawio(d),encoding='utf-8')
    with (ROOT/'STM-TKT-01-transitions.csv').open('w',encoding='utf-8-sig',newline='') as f:
        writer=csv.writer(f);writer.writerow(['Từ','Đến','Sự kiện kích hoạt','Ai kích hoạt','Điều kiện','Tác dụng phụ']);writer.writerows(TRANSITIONS)
    c=canvas.Canvas(str(PDF),pagesize=(W,H));c.setTitle('MedSi - Sơ đồ Sprint 3 đã hiệu chỉnh');c.setAuthor('Codex; dựa trên đặc tả Tuấn Anh')
    for d in diagrams:draw_diagram(c,d)
    table_page(c,TRANSITIONS[:4],1);table_page(c,TRANSITIONS[4:],2);c.save()
    document=pdfium.PdfDocument(str(PDF))
    for i,d in enumerate(diagrams):document[i].render(scale=3).to_pil().save(ROOT/(d['id']+'.png'))
    report={'pdf':str(PDF),'archive':str(ARCHIVE),'page_count':len(document),'page_size_pt':[W,H],'minimum_font_pt':12,'palette':[BLACK,BLUE,RED,WHITE],
        'diagrams':[{'id':d['id'],'nodes':len(d['nodes']),'edges':len(d['edges']),'exceptions':sorted({e['ex'] for e in d['edges'] if e['ex']})} for d in diagrams]}
    (ROOT/'validation.json').write_text(json.dumps(report,ensure_ascii=False,indent=2),encoding='utf-8')
    with zipfile.ZipFile(ARCHIVE,'w',zipfile.ZIP_DEFLATED) as archive:
        for source in sorted(ROOT.iterdir()):
            if source.is_file() and source.suffix in {'.py','.txt','.mmd','.drawio','.png','.csv','.json'}:
                archive.write(source,source.relative_to(PROJECT).as_posix())
        archive.write(PDF,PDF.relative_to(PROJECT).as_posix())
        archive.write(ROOT/'GUIDE.txt','GUIDE.txt')
    print(json.dumps(report,ensure_ascii=True))


if __name__=='__main__':build()
