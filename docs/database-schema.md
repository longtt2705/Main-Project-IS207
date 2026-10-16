# Database Schema — MedSi

Tài liệu này là nguồn tham chiếu cho mô hình dữ liệu của MedSi. Các
migration và API phải dùng đúng tên bảng, tên cột và quan hệ dưới đây.

## ERD

```mermaid
erDiagram
    USERS ||--o{ PATIENT_PROFILES : "owns"
    USERS ||--o{ BOOKINGS : "places"
    PATIENT_PROFILES ||--o{ BOOKINGS : "receives_care_in"
    SPECIALTIES ||--o{ DOCTORS : "contains"
    DOCTORS ||--o{ BOOKINGS : "assigned_to (booking_type=doctor)"
    HOSPITALS ||--o{ EXAM_TYPES : "offers"
    HOSPITALS ||--o{ BOOKINGS : "assigned_to (booking_type=hospital)"
    EXAM_TYPES ||--o{ BOOKINGS : "selected_in"
    SLOTS ||--o{ BOOKINGS : "reserved_in"
    DOCTORS ||..o{ SLOTS : "owns (owner_type=doctor)"
    HOSPITALS ||..o{ SLOTS : "owns (owner_type=hospital)"
    BOOKINGS ||--o| PAYMENTS : "has"
    HOSPITALS ||--o{ HOSPITAL_DEPARTMENTS : "configures"
    SPECIALTIES ||--o{ HOSPITAL_DEPARTMENTS : "available_at"
    HOSPITAL_DEPARTMENTS ||--o{ EXAM_TYPES : "groups"
    HOSPITAL_DEPARTMENTS ||--o{ TICKET_SEQUENCES : "numbers_per_day"
    HOSPITAL_DEPARTMENTS ||--o{ TICKETS : "receives"
    HOSPITALS ||--o{ USERS : "scopes_staff"
    BOOKINGS ||--o| TICKETS : "issues_after_payment"
    PAYMENTS ||--o| REFUND_REQUESTS : "may_request"

    USERS {
        bigint id PK
        varchar name
        varchar email UK
        varchar password
        varchar phone
        varchar role
        bigint hospital_id FK
    }
    PATIENT_PROFILES {
        bigint id PK
        bigint user_id FK
        varchar full_name
        date dob
        enum gender
        varchar phone
        varchar relationship
        varchar national_id
    }
    SPECIALTIES {
        bigint id PK
        varchar name
        varchar image
    }
    DOCTORS {
        bigint id PK
        bigint specialty_id FK
        varchar name
        text bio
        varchar city
        varchar address
        varchar avatar
        timestamp deleted_at
    }
    HOSPITALS {
        bigint id PK
        varchar name
        text description
        varchar address
        varchar city
        varchar hotline
        varchar image
        boolean accepting_bookings
        timestamp deleted_at
    }
    EXAM_TYPES {
        bigint id PK
        bigint hospital_id FK
        bigint hospital_department_id FK
        varchar name
        decimal price
    }
    SLOTS {
        bigint id PK
        enum owner_type
        bigint owner_id
        date work_date
        time start_time
        time end_time
        int capacity
        int booked_count
        enum status
    }
    BOOKINGS {
        bigint id PK
        varchar code UK
        bigint user_id FK
        bigint patient_profile_id FK
        enum booking_type
        bigint doctor_id FK
        bigint hospital_id FK
        bigint exam_type_id FK
        bigint slot_id FK
        text symptoms
        varchar status
        datetime slot_hold_expires_at
        varchar note
        varchar cancel_reason
        datetime cancelled_at
    }
    PAYMENTS {
        bigint id PK
        bigint booking_id FK "UK"
        enum method
        decimal exam_fee
        decimal service_fee
        decimal total_amount
        enum status
        varchar transaction_code
        datetime paid_at
    }
    HOSPITAL_DEPARTMENTS {
        bigint id PK
        bigint hospital_id FK
        bigint specialty_id FK
        int max_shift_capacity
        varchar room_number
        text instructions
    }
    TICKET_SEQUENCES {
        bigint id PK
        bigint hospital_department_id FK
        date appointment_date
        int last_number
    }
    TICKETS {
        bigint id PK
        bigint booking_id FK "UK"
        bigint hospital_department_id FK
        date appointment_date
        varchar ticket_code UK
        text qr_token
        varchar queue_number
        varchar room_number
        varchar status
        datetime check_in_at
        datetime expires_at
        text instructions
    }
    REFUND_REQUESTS {
        bigint id PK
        bigint payment_id FK "UK"
        int amount
        varchar reason
        varchar status
    }
```

## Bảng và quy tắc

| Bảng | Vai trò và ràng buộc chính |
|---|---|
| `users` | Tài khoản đăng nhập; `email` duy nhất; `role` gồm `patient`, `admin`, `hospital_admin`, `staff`, `kiosk`. Các role viện cần `hospital_id`. Bác sĩ chưa có role tài khoản riêng. |
| `patient_profiles` | Người được chăm sóc thuộc một tài khoản qua `user_id`; lưu thông tin người bệnh và quan hệ với chủ tài khoản. |
| `specialties` | Danh mục chuyên khoa, gồm tên và ảnh. |
| `doctors` | Hồ sơ bác sĩ thuộc một `specialty_id`; có `address`, index theo `specialty_id` và `city`, dùng soft delete. Không gắn trực tiếp với tài khoản `users`. |
| `hospitals` | Cơ sở khám; `accepting_bookings` chặn đặt/thanh toán mới khi đóng nhận khám; dùng soft delete và index theo `city`. |
| `hospital_departments` | Unique `(hospital_id, specialty_id)`; cấu hình sức chứa mỗi ca, phòng và hướng dẫn. |
| `exam_types` | Dịch vụ thuộc viện và department; dùng soft delete. Giữ `price` để tương thích dữ liệu cũ; phí BKH được cố định 200.000đ khám + 20.000đ dịch vụ. |
| `slots` | Khung giờ dùng đa hình: `owner_type` là `doctor` hoặc `hospital`, `owner_id` trỏ tới thực thể tương ứng. `booked_count` không vượt quá `capacity`. |
| `bookings` | Lượt đặt lịch; `code` duy nhất. `booking_type` quyết định dùng `doctor_id` hay `hospital_id`/`exam_type_id`. Không unique cứng theo hồ sơ/slot để cho đặt lại sau hủy; service kiểm tra trùng lịch active trong transaction. |
| `payments` | Tối đa một thanh toán cho mỗi booking (`booking_id` duy nhất); tổng tiền gồm `exam_fee`, `service_fee` và `total_amount`, có thể ghi `paid_at`. |
| `ticket_sequences` | Unique `(hospital_department_id, appointment_date)`; bộ đếm STT tăng, không giảm/tái sử dụng khi hủy. |
| `tickets` | Một phiếu/booking; `ticket_code` duy nhất; unique department/ngày/STT. QR là JWT HS256 được kiểm tra cùng trạng thái trong DB. |
| `refund_requests` | Một yêu cầu/payment; `amount` là số nguyên VNĐ. `pending` chờ bộ xử lý PAY, không biểu thị đã hoàn tiền ngân hàng. |

Khi tạo hoặc hủy booking, cập nhật `slots.booked_count` trong cùng transaction.
Luồng hospital khóa theo thứ tự user → hospital → slot → booking → payment;
khóa viện bảo vệ cả sức chứa department/ca khi đặt các slot khác nhau. Booking
đang giữ chỗ có thể dùng `slot_hold_expires_at`; quy trình dọn giữ chỗ phải
giải phóng số lượng đã đặt khi thời hạn hết.

`owner_type` của `slots` chỉ nhận `doctor` hoặc `hospital`; cặp
`owner_type`/`owner_id` được kiểm soát ở tầng Eloquent vì đây là quan hệ đa hình
và không có FK database. Booking loại `doctor` phải có `doctor_id` và để
`hospital_id`/`exam_type_id` null; booking loại `hospital` phải có cả
`hospital_id` và `exam_type_id`, để `doctor_id` null. Các điều kiện này do
Booking Service bảo đảm.

Trạng thái hospital booking: `pending_payment` → `confirmed` → `checked_in` →
`completed`; các nhánh kết thúc gồm `cancelled` và `no_show`. Booking bác sĩ vẫn
có `pending`/`rejected`. Ticket có `issued`, `checked_in`, `completed`, `cancelled`,
`expired`; hết ca + 120 phút mà chưa check-in sẽ chuyển ticket `expired` và booking `no_show`.
