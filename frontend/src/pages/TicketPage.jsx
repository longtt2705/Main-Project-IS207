import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import QRCode from 'qrcode'
import { hospitalApi } from '@/api/hospitalApi'
import { Button } from '@/components/ui/button'
import { formatCurrency, formatDateVN } from '@/lib/mockData'

const labels = { issued: 'Đã phát hành', checked_in: 'Đã check-in', completed: 'Đã khám', cancelled: 'Đã hủy', expired: 'Đã hết hạn' }

export default function TicketPage() {
  const { bookingId } = useParams()
  const [ticket, setTicket] = useState(null)
  const [qr, setQr] = useState('')
  const [error, setError] = useState('')
  useEffect(() => {
    let active = true
    hospitalApi.ticket(bookingId).then(async (res) => {
      const image = res.data.qr_token ? await QRCode.toDataURL(res.data.qr_token, { width: 480, margin: 4, errorCorrectionLevel: 'M' }) : ''
      if (active) { setTicket(res.data); setQr(image) }
    }).catch((err) => { if (active) setError(err.message) })
    return () => { active = false }
  }, [bookingId])
  return <main className="max-w-2xl mx-auto p-6 space-y-4">
    <style>{`@media print { @page { size: A5; margin: 12mm; } body * { visibility: hidden; } #medical-ticket, #medical-ticket * { visibility: visible; } #medical-ticket { position: absolute; inset: 0 auto auto 0; width: 100%; border: 0; padding: 0; } .ticket-actions { display: none; } }`}</style>
    <div className="ticket-actions"><Link to="/my-bookings" className="text-sky-700">← Lịch khám của tôi</Link></div>
    {error && <p role="alert" className="text-red-700">{error}</p>}
    {!ticket && !error && <p>Đang tải phiếu khám…</p>}
    {ticket && <>
      <article id="medical-ticket" className="bg-white border rounded-2xl p-6 space-y-4">
        <header className="text-center space-y-1"><p className="font-bold text-sky-700">MEDSI · PHIẾU KHÁM ĐIỆN TỬ</p><h1 className="text-xl font-bold">{ticket.hospital.name}</h1><p className="text-sm">{ticket.hospital.address}</p><p className="font-mono">{ticket.ticket_code}</p></header>
        <div className="text-center"><p className="text-sm">Số thứ tự · {ticket.specialty}</p><p className="text-4xl font-black py-2">{ticket.queue_number}</p><p>{labels[ticket.status]}</p>{qr ? <img src={qr} alt="Mã QR xác thực phiếu khám" className="w-60 max-w-full mx-auto" /> : <p className="p-4 text-slate-600">Mã QR không còn hiệu lực để check-in.</p>}</div>
        <div className="border-t pt-4 space-y-2"><h2 className="font-bold">{ticket.patient.full_name}</h2><p>Năm sinh: {ticket.patient.birth_year} · {ticket.patient.gender === 'male' ? 'Nam' : ticket.patient.gender === 'female' ? 'Nữ' : 'Khác'}</p><p>SĐT: {ticket.patient.phone} · CCCD: {ticket.patient.national_id || "Chưa cung cấp"}</p><p>Ngày khám: {formatDateVN(ticket.appointment_date)} · Ca {ticket.shift === 'morning' ? 'sáng' : 'chiều'}</p><p>Giờ tiếp nhận dự kiến: {ticket.estimated_time}</p><p>Phòng: {ticket.room_number || 'Chờ phân bổ tại quầy'}</p><p>Phí khám: {formatCurrency(ticket.payment?.exam_fee)} · Phí tiện ích: {formatCurrency(ticket.payment?.service_fee)}</p></div>
        <section className="border-t pt-4"><h2 className="font-bold">Chuẩn bị trước khi khám</h2><p className="whitespace-pre-line mt-2">{ticket.instructions}</p></section>
      </article>
      <div className="ticket-actions flex flex-wrap gap-3">
        {qr && <Button asChild><a href={qr} download={`${ticket.ticket_code}-QR.png`}>Tải ảnh QR</a></Button>}
        <Button variant="outline" onClick={() => window.print()}>In phiếu / Lưu PDF</Button>
      </div>
    </>}
  </main>
}
