import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom"
import { AuthProvider } from "@/context/AuthContext"
import PageLayout from "@/components/layout/PageLayout"

// Core screens according to project specifications
import HomePage from "@/pages/HomePage"
import SearchResultsPage from "@/pages/SearchResultsPage"
import DoctorDetailPage from "@/pages/DoctorDetailPage"
import BookingConfirmPage from "@/pages/BookingConfirmPage"
import PaymentPage from "@/pages/PaymentPage"
import LoginPage from "@/pages/LoginPage"
import RegisterPage from "@/pages/RegisterPage"
import MyBookingsPage from "@/pages/MyBookingsPage"
import AdminBookingsPage from "@/pages/Admin/AdminBookingsPage"
import AdminCatalogPage from "@/pages/Admin/AdminCatalogPage"
import NotFoundPage from "@/pages/NotFoundPage"

import RequireAuth from '@/components/RequireAuth'
import HospitalsPage from '@/pages/HospitalsPage'
import HospitalBookingPage from '@/pages/HospitalBookingPage'
import TicketPage from '@/pages/TicketPage'
import ReceptionPage from '@/pages/ReceptionPage'
import HospitalAdminPage from '@/pages/HospitalAdminPage'

function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          {/* All main screens use PageLayout (Navbar + Outlet + Footer) */}
          <Route element={<PageLayout />}>
            {/* 1. Trang chủ */}
            <Route path="/" element={<HomePage />} />
            <Route path="/hospitals" element={<HospitalsPage />} />
            <Route path="/hospitals/:id" element={<HospitalBookingPage />} />
            <Route path="/bookings/:bookingId/ticket" element={<RequireAuth><TicketPage /></RequireAuth>} />
            <Route path="/reception" element={<RequireAuth><ReceptionPage /></RequireAuth>} />
            <Route path="/hospitals/:id/manage" element={<RequireAuth><HospitalAdminPage /></RequireAuth>} />

            {/* 2. Danh sách bác sĩ, bệnh viện */}
            <Route path="/doctors" element={<SearchResultsPage />} />

            {/* 3. Chi tiết bác sĩ, bệnh viện + chọn slot */}
            <Route path="/doctors/:id" element={<DoctorDetailPage />} />

            {/* 3.1. Xác nhận đặt lịch */}
            <Route path="/booking/confirm" element={<RequireAuth><BookingConfirmPage /></RequireAuth>} />

            {/* 3.2. Thanh toán đặt khám */}
            <Route path="/payment" element={<RequireAuth><PaymentPage /></RequireAuth>} />
            <Route path="/payments" element={<Navigate to="/payment" replace />} />

            {/* 4. Đăng nhập / Đăng ký */}
            <Route path="/login" element={<LoginPage />} />
            <Route path="/register" element={<RegisterPage />} />

            {/* 5. Lịch sử đặt lịch của tôi */}
            <Route path="/my-bookings" element={<RequireAuth><MyBookingsPage /></RequireAuth>} />

            {/* 6. Trang quản trị Admin */}
            <Route path="/admin" element={<Navigate to="/admin/bookings" replace />} />
            <Route path="/admin/bookings" element={<AdminBookingsPage />} />
            <Route path="/admin/catalog" element={<AdminCatalogPage />} />

            {/* Fallback 404 */}
            <Route path="*" element={<NotFoundPage />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  )
}

export default App
