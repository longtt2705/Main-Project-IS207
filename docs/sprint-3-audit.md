# Đối chiếu yêu cầu Sprint 3 và mã nguồn

Ngày kiểm tra: 16/10/2026. Phạm vi là miền **BKH/TKT** của Tuấn Anh trong [kho yêu cầu Sprint 3](https://github.com/vuttuananh168/task-sprint-3/tree/3a2e97d61208a91a261cffc6a0b679e8e2175e29). Đối chiếu đặc tả `BKH_DOC / TKT_SPEC`, các sheet `BKH_BR`, `TKT_BR`, `BKH_DD`, bảng chuyển trạng thái `TKT_STM`, và mục C5 của PDF phân tích thiết kế. Các yêu cầu về báo cáo, phỏng vấn và khảo sát đối thủ là sản phẩm học thuật trong kho yêu cầu, không phải chức năng chạy của ứng dụng.

## 1. Các sai lệch đã sửa

Trước khi sửa, giao diện bệnh viện đọc dữ liệu giả, xác nhận đặt lịch chỉ ghi LocalStorage, thanh toán luôn dùng booking số 1 và có thể báo thành công dù API thất bại. API hồ sơ dùng sai tên token (`auth_token` thay vì `access_token`) và tự chuyển sang dữ liệu giả khi nhận lỗi. Lịch sử thiếu phân trang sử dụng được, tra cứu mã phiếu và đường dẫn đến phiếu thật.

Backend có giữ chỗ theo slot nhưng thiếu giới hạn ngày, sức chứa theo ca/chuyên khoa, giới hạn tài khoản và chống trùng chuyên khoa/ngày. Không có thực thể ticket, số thứ tự, QR có chữ ký, tiếp đón, hủy lịch bệnh viện, yêu cầu hoàn tiền và lịch chạy tự động giải phóng giữ chỗ. Phí dịch vụ trước đây là 0 lúc đặt và 10.000đ khi thanh toán, khác bộ phí đã chốt trong BKH_BR.

| Quy tắc | Hành vi hiện tại | Nơi thực hiện chính |
|---|---|---|
| BR-BKH-01 | Chỉ cho đặt ngày mai đến ngày thứ 30, theo GMT+7 | HospitalBookingPolicy; HospitalService |
| BR-BKH-02 | Giới hạn tổng số hồ sơ theo bệnh viện/chuyên khoa/ngày/ca; tính cả giữ chỗ còn hạn; hiển thị ca đầy | BookingService; HospitalDepartment; HospitalService |
| BR-BKH-03 | Giữ đúng 15 phút; client không được kéo dài thời hạn | BookingService |
| BR-BKH-04 | Tự hủy giữ chỗ hết hạn, giảm booked_count đúng một lần | SlotHoldService; bookings:expire |
| BR-BKH-05 | Từ chối tạo thêm và chặn thanh toán hold thứ tư khi đã có 3 lịch confirmed chưa diễn ra | BookingService; PaymentService |
| BR-BKH-06 | Chặn cùng hồ sơ/chuyên khoa/ngày, kể cả khác dịch vụ hoặc khung giờ | BookingService |
| BR-BKH-07 | Thanh toán và xác nhận booking/phát hành ticket trong cùng transaction | PaymentService; TicketService |
| BR-BKH-08 | Bệnh nhân hủy trước ca khám ít nhất 24h: tạo yêu cầu hoàn 200.000đ phí khám | HospitalCancellationService; refund_requests |
| BR-BKH-09 | Dưới 24h: từ chối hủy trực tuyến và hướng dẫn liên hệ viện | HospitalCancellationService |
| BR-BKH-10 | Quản trị viện ngừng tiếp nhận: hủy lịch chưa check-in sắp tới, yêu cầu hoàn cả phí khám và tiện ích | HospitalAdminController; HospitalCancellationService |
| BR-BKH-11 | Lịch sử/phiếu chỉ trả dữ liệu của tài khoản đăng nhập; lỗi xác thực không dùng dữ liệu giả | BookingService; TicketController; axiosClient |
| BR-BKH-12 | Sau kết thúc ca 120 phút chưa check-in: booking no_show, ticket expired | TicketService; bookings:expire |
| BR-BKH-13 | Đặt bệnh viện không chọn bác sĩ; doctor_id luôn null lúc tạo | HospitalBookingPage; BookingService |
| BR-BKH-14 | 200.000đ phí khám + 20.000đ phí tiện ích = 220.000đ; cùng số liệu ở giữ chỗ, hóa đơn và thanh toán | HospitalBookingPolicy; PaymentService |
| BR-TKT-01 | Một ticket duy nhất cho booking hospital đã confirmed và paid | TicketService; unique tickets.booking_id |
| BR-TKT-02 | TKT-YYYYMMDD-XXXXXX, 6 ký tự từ CSPRNG, bỏ O/0/I/1, unique trong DB | TicketService |
| BR-TKT-03 | JWT ký HMAC-SHA256; kiểm tra chữ ký, hết hạn và token đã lưu trước check-in | TicketService |
| BR-TKT-04 | STT theo bệnh viện/chuyên khoa/ngày khám; sequence có khóa, bắt đầu 001 | ticket_sequences; TicketService |
| BR-TKT-05 | Hủy booking đồng thời hủy ticket; QR không được hiển thị và bị từ chối khi quét | HospitalCancellationService; TicketResource |
| BR-TKT-06 | Không giảm sequence hoặc tái sử dụng STT khi hủy | TicketService |
| BR-TKT-07 | Tài khoản tiếp đón/Kiosk được phân đúng viện quét QR để chuyển issued → checked_in | TicketController; ReceptionPage |
| BR-TKT-08 | Từ chối check-in sai ngày hẹn | TicketService |
| BR-TKT-09 | Từ chối quét lần hai, trả thời gian đã tiếp nhận | TicketService |
| BR-TKT-10 | Ticket chưa sử dụng hết hạn theo mốc 120 phút sau ca | TicketService |
| BR-TKT-11 | Phiếu có dặn dò chung và dặn dò chuyên khoa được cấu hình, bản chụp tại lúc phát hành | HospitalAdminPage; TicketService; TicketPage |
| BR-TKT-12 | Nhân viên được phân viện xác nhận đã khám: checked_in → completed; kiosk không được hoàn tất | TicketService; ReceptionPage |

Ngoài các rule trên, đã bổ sung màn hình bệnh viện thật, chọn chuyên khoa/ca/giờ/hồ sơ, đếm ngược thanh toán, lịch sử theo nhóm Sắp khám/Đã khám/Đã hủy hoặc vắng mặt, CCCD tùy chọn trong hồ sơ và che số trên phiếu, tải PNG QR, in/Lưu PDF khổ A5 và giữ trang đích khi đăng nhập lại. Quản trị viện có thể tạo slot 30 phút, chỉnh sức chứa/dặn dò/phòng khám và ngừng/mở lại tiếp nhận. Loại bỏ các nút đổi role thử nghiệm không hoạt động khỏi thanh điều hướng.

## 2. Các quyết định khi tài liệu khác nhau

- **Thời điểm hết hạn:** TKT_BR-10 diễn đạt “kết thúc ca”, nhưng bảng TKT_STM và BR-BKH-12 ghi rõ thêm 120 phút. Dùng cùng mốc kết thúc ca + 120 phút cho QR, ticket và no_show để trạng thái không mâu thuẫn.
- **Mã phiếu:** Ví dụ trong tài liệu có đuôi 5 ký tự; dùng quy tắc 6 ký tự được viết rõ ở TKT_SPEC và BR-TKT-02.
- **Tên thực thể:** Giữ `bookings` và `hospitals` hiện có, lần lượt tương đương `appointments` và `facilities`. `tickets.booking_id` tương đương `appointment_id`. Không đổi tên toàn bộ schema đang dùng bởi các miền khác.
- **Chuyên khoa viện:** `hospital_departments` liên kết viện và chuyên khoa; nhiều `exam_types` có thể cùng một department. Sức chứa và STT dùng department, không dùng riêng từng dịch vụ. Migration dữ liệu cũ tạo department theo tên dịch vụ khi chưa có mapping; cần rà soát mapping chuyên khoa thực tế trước khi dùng dữ liệu ngoài bài tập.
- **Hoàn tiền:** BKH tạo `refund_requests` trong cùng transaction hủy lịch, amount 200.000đ khi bệnh nhân hủy hợp lệ và toàn bộ phí đã trả khi viện hủy. `pending` là lệnh bàn giao cho miền PAY, không phải xác nhận ngân hàng đã hoàn tiền. Bộ xử lý hoàn tiền thật thuộc tích hợp PAY.
- **Hoàn tất khám:** Có thao tác xác nhận thủ công cho nhân viên viện; không giả lập việc đã tích hợp HIS. Kiosk chỉ check-in.

## 3. Cài đặt và chạy

Trong `backend`, cài dependencies và chạy migration mới trên database của dự án:

```sh
composer install
php artisan migrate
php artisan tickets:issue-missing
```

Lệnh `tickets:issue-missing` cấp phiếu cho các lịch hospital đã paid/confirmed từ trước khi tính năng ticket tồn tại; chạy lại không cấp trùng. Không chạy `migrate:fresh` trên dữ liệu cần giữ. Với database mới hoàn toàn, dùng `php artisan migrate --seed` để có bệnh viện, chuyên khoa và slot mẫu hợp lệ. Slot cũ không đúng 30 phút được giữ lại cho lịch sử nhưng không mở để đặt mới; có thể tạo slot hợp lệ tại `/hospitals/:id/manage`.

`APP_KEY` phải có giá trị (`php artisan key:generate` khi thiết lập mới). Múi giờ mặc định là `Asia/Ho_Chi_Minh`. `.env.example` dùng cache/session dạng file và queue sync vì dự án chưa có các bảng cache/session/job.

Để thực hành thanh toán giả lập tại local, đặt trong `backend/.env`:

```ini
PAYMENT_DEMO_GATEWAY_ENABLED=true
PAYMENT_DEMO_OTP=123456
```

Cổng giả lập không thu tiền thật. Khi triển khai gateway thực, cần thay xác nhận giả lập bằng callback đã xác minh của nhà cung cấp. Phí BKH dùng bộ hằng số Sprint 3; biến `PAYMENT_SERVICE_FEE` cũ không thay đổi phí BKH.

Chạy scheduler trong terminal riêng để giải phóng giữ chỗ và đánh dấu vắng mặt tự động:

```sh
php artisan schedule:work
```

Trong môi trường có cron, chạy `php artisan schedule:run` mỗi phút. Có thể kiểm tra một lượt bằng `php artisan bookings:expire`.

Trong `frontend`:

```sh
npm ci
npm run dev
```

Luồng bệnh nhân: `/hospitals` → `/hospitals/:id` → `/payment?booking_id=...` → `/bookings/:id/ticket`. Hồ sơ bệnh nhân và lịch sử ở `/my-bookings`. Tiếp đón ở `/reception`; máy quét QR dạng bàn phím nhập token vào ô QR rồi gửi. Tải QR thực hiện trên trình duyệt, không gửi token tới dịch vụ tạo QR bên ngoài. Nút In/Lưu PDF mở hộp thoại in để chọn Save as PDF.

Tài khoản `admin` có quyền hệ thống; `staff`, `hospital_admin`, `kiosk` phải được cấp `users.hospital_id` đúng viện. Đăng ký công khai vẫn chỉ tạo patient; không có đổi role từ giao diện. Việc cấp các tài khoản nghiệp vụ này do quản trị viên thực hiện qua dữ liệu quản trị, không qua API đăng ký bệnh nhân.

## 4. Kiểm chứng

- PHPUnit trên MariaDB/InnoDB và database thử nghiệm riêng: **135 tests, 635 assertions, pass**. Bao gồm khóa dòng thật, hai tiến trình tranh sức chứa ca ở các slot khác nhau, giới hạn khi giữ chỗ lẫn khi thanh toán, độc quyền dữ liệu, CCCD tùy chọn và che số, HMAC giả mạo, ngày hẹn, quét lặp, sequence, hủy/hoàn phí, no-show và backfill.
- Frontend production build và ESLint trên các file frontend thay đổi.
- Kiểm tra trình duyệt Chrome: đăng nhập trở lại trang đích, chọn viện/slot/hồ sơ, thanh toán qua API, phiếu QR, lịch sử, viewport 390px và PDF A5. Không có lỗi JavaScript trong luồng kiểm tra.
- Lệnh lint toàn frontend vẫn có lỗi tồn tại trước ở các component thanh toán demo cũ không còn dùng trong PaymentPage, `components/ui/dialog.jsx` và `AuthContext.jsx`; đây không phải kết quả pass toàn bộ lint repository.

Các câu hỏi **Open** OQ-BKH-01 đến 06 không được coi là rule đã chốt: khóa tài khoản vì nhiều lần no-show, nhắc lịch Push/Email/SMS, cơ chế dự phòng Kiosk, thu thập BHYT, phân bác sĩ tự động và tích hợp HIS đầy đủ vẫn cần quyết định/tích hợp của nhóm. Không tạo dữ liệu phỏng vấn, ảnh khảo sát hay bằng chứng học thuật giả.

## 5. Sơ đồ C5 được tái dựng ngày 17/10/2026

Đã đọc lại PDF yêu cầu và tái dựng **ACT-BKH-01, ACT-BKH-02, ACT-BKH-03, STM-TKT-01**. Các file Draw.io trong kho yêu cầu chỉ có khung/lane hoặc tiêu đề, không có cạnh nối; bản mới có đồ thị chỉnh sửa được. Bộ mới sửa bố cục A4 ngang, nhãn tiếng Việt, swimlane, điều kiện rẽ nhánh, mã EX, vòng đời phiếu và khung tên theo A1/A4/A5/C5. Tên trạng thái chung và nghĩa của EX-BKH-01 đến 06 được giữ nguyên.

- [PDF bốn sơ đồ và bảng chuyển trạng thái](../output/pdf/sprint-3-diagrams.pdf).
- [Toàn bộ bản xuất và nguồn chỉnh sửa](../output/sprint-3-diagrams.zip).
- [Hướng dẫn, giải thích từng hình, nguồn và điểm cần chốt](diagrams/sprint-3/GUIDE.txt).
- [Nguồn tái tạo](diagrams/sprint-3/generate_diagrams.py); các file Mermaid, Draw.io, PNG và bảng CSV nằm cùng thư mục.

A2 ưu tiên mã Mermaid trong Notion trong khi bảng C5 vẫn ghi Draw.io, nên bộ bàn giao có cả hai. PDF/PNG/Draw.io giữ bố cục in A4; Mermaid có thể tự sắp xếp khác. Đã kiểm tra render Mermaid, đồ thị Draw.io, đầu nối, số node, mã ngoại lệ và toàn bộ sáu trang PDF. Mốc hết hạn +120 phút và ranh giới HIS vẫn được đánh dấu cần nhóm xác nhận; bộ sơ đồ không biến các giả định triển khai ở mục 2 thành quyết định đã được nhóm duyệt.
