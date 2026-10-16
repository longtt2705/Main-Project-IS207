# Payment: handoff M4/M5 và hợp đồng API

## Phạm vi và tình trạng phối hợp

Luồng hiện tại kết nối BookingService, PaymentService và TicketService với API và
frontend thật. Quy tắc BKH/TKT được đối chiếu trong [báo cáo Sprint 3](sprint-3-audit.md).
Payment cập nhật booking và cấp phiếu trong cùng transaction khi thanh toán thành công.

## Trách nhiệm giữ slot

- M5 tạo hospital booking `pending_payment`, đặt `slot_hold_expires_at` (hiện mặc định
  15 phút), tăng `slots.booked_count`, chuyển `available` thành `full` khi đầy.
- M5 tạo sẵn payment `pending`; Payment tái sử dụng record theo `booking_id` unique.
  Phí BKH cố định 200.000đ khám + 20.000đ dịch vụ ở cả lúc giữ chỗ và thanh toán;
  không nhận tổng tiền từ client. `PAYMENT_SERVICE_FEE` không ghi đè quy tắc BKH.
- Payment không tăng booked_count khi thanh toán thành công: chỗ đã được M5 giữ.
- Khi `now >= slot_hold_expires_at`, Payment hủy hospital booking còn
  `pending_payment`, đổi payment `pending` thành `expired`, giảm số chỗ đúng một lần.
  `full` trở lại `available`; `blocked` vẫn giữ nguyên. Booking đã hủy là dấu hiệu
  chống giải phóng lặp. Giữ lại deadline để tra cứu.
- Cleanup cũng giải phóng hold của booking có payment `failed`, `cancelled`,
  `expired` hoặc chưa có payment, nhưng giữ nguyên kết quả payment cũ. Không đụng
  booking doctor hoặc payment `paid`/`refunded`.
- Deadline null là dữ liệu không hợp lệ: từ chối thanh toán, không tự suy diễn hết
  hạn và không tự giải phóng. Cần M5 bảo đảm deadline luôn có khi giữ chỗ.
- Hủy payment không có nghĩa hủy booking: còn thời hạn thì được đổi phương thức/thử
  lại trên cùng record, không gia hạn hold. Sau khi hold bị giải phóng phải đặt
  booking mới. Store Pay giả lập cũng dùng deadline chung, không cam kết 24 giờ.
- Các đường ghi khóa theo thứ tự **user -> hospital -> slot -> booking -> payment**.
  Hospital booking lấy khóa viện trước các truy vấn snapshot để đọc đúng sức chứa
  sau khi chờ transaction khác. Giới hạn 3 lịch xác nhận được kiểm tra lại khi trả tiền.
- Scheduler chạy `bookings:expire` mỗi phút để giải phóng hold và đánh dấu no-show.
  Chạy `php artisan schedule:work` ở local hoặc `schedule:run` mỗi phút qua cron.
  Đọc payment, tạo payment, tính invoice và process cũng kiểm tra hạn.
- Service tự mở transaction. Nếu caller bọc thêm transaction ngoài và rollback khi
  nhận SlotHoldExpiredException thì cleanup bên trong cũng rollback; API Payment
  gọi trực tiếp service và không tạo transaction ngoài.

## Process giả lập

`processPayment(int|Payment $payment, int $userId, ?string $otp = null)`:

1. Kiểm tra chủ sở hữu và lấy khóa theo thứ tự trên.
2. Trả lại kết quả cũ nếu đã paid; không tạo mã giao dịch hoặc paid_at mới.
3. Kiểm tra giữ chỗ, loại booking, trạng thái, patient/hospital/exam/slot nhất quán.
4. Yêu cầu `PAYMENT_DEMO_GATEWAY_ENABLED=true` cho tất cả phương thức.
5. ATM/credit_card yêu cầu OTP 6 số khớp `PAYMENT_DEMO_OTP` (mặc định 123456).
   OTP sai/thiếu hợp lệ ở tầng service ghi failed trước khi trả lỗi; validation
   sai định dạng tại API trả 422 và không cập nhật trạng thái.
6. Ghi paid, mã giao dịch giả TXN..., paid_at, booking confirmed và một ticket có
   QR ký HS256 trong cùng transaction; xóa deadline giữ chỗ. Lỗi ghi rollback toàn bộ.

Hủy lịch hợp lệ tạo `refund_requests` trạng thái `pending`, chuyển phiếu sang
`cancelled` và trả lại chỗ. Yêu cầu hoàn phí này chờ miền PAY xử lý; chưa phải
xác nhận hoàn tiền từ ngân hàng.

Đây chỉ là giả lập để demo. Không dùng endpoint này để xác nhận tiền thật; khi tích
hợp gateway cần webhook được xác minh, tham chiếu attempt và số tiền đối soát.

## API Payment

Các route giữ `auth:sanctum`, client gửi Bearer token và `Accept: application/json`.

| HTTP | Endpoint | Body |
| --- | --- | --- |
| POST | /api/payments/calculate-invoice | booking_id: ID nguyên dương hoặc code BK... |
| POST | /api/payments/create | booking_id, method |
| POST | /api/payments/process | payment_id, otp (thẻ) |
| POST | /api/payments/confirm | Alias tương thích của process |
| GET | /api/payments/{id} | Không có body |
| POST | /api/payments/{id}/cancel | Không có body |

Controller/validation của Payment trả `{success, message, data, errors}`;
success trả errors null, lỗi trả data null. `data.status` và `data.method` vẫn là
object có `code`, giữ tương thích paymentApi.js. Middleware Sanctum trả 401 khi
chưa đăng nhập. Các API Hospital/Booking/Ticket và frontend gọi luồng này trực tiếp.

Status HTTP: 200 đọc/process/hủy, 201 create, 401 chưa đăng nhập, 404 không tìm thấy
hoặc không thuộc user, 422 validation, 409 xung đột trạng thái/hết hạn. Exception
`App\Exceptions\Payment\SlotHoldExpiredException` kế thừa BusinessException,
status 409; PaymentController xử lý cục bộ. Các API khác có renderer chung cho
BusinessException và DomainException.

## Kiểm thử

PaymentWorkflowTest sử dụng BookingService thật để tạo fixture Hospital. Bao phủ:
5 phương thức, phí M5 -> Payment, idempotency, biên deadline, cleanup nhiều lần,
failed/cancelled/no-payment, slot blocked, deadline null, retry không gia hạn,
gateway disabled, quyền sở hữu, stale model, payload giả mạo, validation/API alias,
đặt lại sau hết hạn và rollback khi lỗi ghi booking/slot.

Chạy an toàn trên SQLite RAM bằng PowerShell từ backend (env chỉ đổi trong shell):

```powershell
$env:DB_CONNECTION='sqlite'
$env:DB_DATABASE=':memory:'
$env:DB_URL=''
$env:APP_ENV='testing'
php artisan test --filter=Payment
```

Các bài kiểm tra khóa dòng và cạnh tranh sức chứa tự skip khi dùng SQLite.
Chạy toàn bộ `php artisan test` với database MySQL/MariaDB thử nghiệm riêng để
kiểm tra InnoDB, gồm hai tiến trình đặt các slot khác nhau trong cùng ca khám.
Không chạy RefreshDatabase hoặc DatabaseMigrations trên database cần giữ dữ liệu.
