import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import paymentApi from '@/api/paymentApi'
import api from '@/api/axiosClient'
import { Button } from '@/components/ui/button'
import { formatCurrency } from '@/lib/mockData'

export default function PaymentPage() {
  const [params] = useSearchParams()
  const bookingId = params.get('booking_id')
  const [invoice, setInvoice] = useState(null)
  const [payment, setPayment] = useState(null)
  const [method, setMethod] = useState('qr_pay')
  const [otp, setOtp] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [now, setNow] = useState(Date.now)

  useEffect(() => {
    let active = true
    if (!bookingId) return
    // A reload after payment must recover the real result, not create another charge.
    api.get(`/bookings/${bookingId}`).then(async (res) => {
      if (!active) return
      if (res.data.payment?.status === 'paid') {
        setPayment(res.data.payment)
      } else {
        const result = await paymentApi.calculateInvoice(bookingId)
        if (active) setInvoice(result.data)
      }
    }).catch((err) => { if (active) setError(err.message) })
    return () => { active = false }
  }, [bookingId])
  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(timer)
  }, [])
  const seconds = invoice ? Math.max(0, Math.ceil((new Date(invoice.slot_hold_expires_at).getTime() - now) / 1000)) : 0

  async function pay(event) {
    event.preventDefault()
    setBusy(true); setError('')
    try {
      const booking = await api.get(`/bookings/${bookingId}`)
      const existing = booking.data.payment
      if (existing?.status === 'paid') { setPayment(existing); return }
      // Changing payment method cancels only that attempt; the original hold deadline is retained.
      if (existing?.status === 'pending' && existing.method !== method) await paymentApi.cancelPayment(existing.id)
      const created = await paymentApi.createPayment({ booking_id: Number(bookingId), method })
      const result = await paymentApi.confirmPayment({ payment_id: created.data.id, otp: otp || undefined })
      if ((result.data.status?.code || result.data.status) !== 'paid') throw new Error('Chưa nhận được xác nhận thanh toán thành công.')
      setPayment(result.data)
    } catch (err) { setError(err.message) }
    finally { setBusy(false) }
  }

  return <main className="max-w-2xl mx-auto p-6 space-y-6">
    <h1 className="text-3xl font-bold">Thanh toán đặt khám MedSi</h1>
    {(!bookingId || error) && <p role="alert" className="bg-red-50 p-4 text-red-700 rounded-lg">{error || 'Vui lòng chọn lịch khám trước khi thanh toán.'}</p>}
    {payment ? <section className="space-y-4 bg-white border rounded-xl p-6">
      <h2 className="text-xl font-bold text-emerald-700">Thanh toán đã được ghi nhận</h2>
      <p>Mã giao dịch: {payment.transaction_code}</p>
      <p>Tổng tiền: {formatCurrency(payment.total_amount)}</p>
      <Button asChild><Link to={`/bookings/${bookingId}/ticket`}>Xem phiếu khám và mã QR</Link></Button>
      <Link to="/my-bookings" className="block text-sky-700">Lịch khám của tôi</Link>
    </section> : invoice ? <form onSubmit={pay} className="space-y-4 bg-white border rounded-xl p-6">
      <h2 className="font-bold text-xl">{invoice.hospital.name}</h2>
      <p>{invoice.patient.full_name} — {invoice.exam_type.name}</p>
      <p>{invoice.schedule.work_date} · {invoice.schedule.time_slot}</p>
      <p className="font-semibold" role="status">{seconds > 0 ? `Còn ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')} để thanh toán` : 'Đã hết thời gian giữ chỗ. Vui lòng đặt lại lịch.'}</p>
      <dl className="border-y py-3 space-y-2"><div className="flex justify-between"><dt>Phí khám</dt><dd>{formatCurrency(invoice.fees.exam_fee)}</dd></div><div className="flex justify-between"><dt>Phí tiện ích</dt><dd>{formatCurrency(invoice.fees.service_fee)}</dd></div><div className="flex justify-between font-bold"><dt>Tổng tiền</dt><dd>{formatCurrency(invoice.fees.total_amount)}</dd></div></dl>
      <label className="block">Phương thức thanh toán<select value={method} onChange={(e) => setMethod(e.target.value)} className="block w-full border p-3 rounded-lg mt-2">{invoice.available_methods.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
      {invoice.demo_gateway && <p className="text-sm text-amber-800">Cổng thanh toán mô phỏng dành cho bài tập. Không phát sinh giao dịch tiền thật.</p>}
      {['atm', 'credit_card'].includes(method) && <label className="block">Mã OTP<input required inputMode="numeric" autoComplete="one-time-code" value={otp} onChange={(e) => setOtp(e.target.value)} className="block border rounded-lg p-3 mt-2 w-full" /></label>}
      <Button disabled={busy || seconds <= 0} type="submit">{busy ? 'Đang xử lý…' : 'Xác nhận thanh toán'}</Button>
    </form> : bookingId && !error && <p>Đang tải hóa đơn…</p>}
    <Link to="/hospitals" className="text-sky-700 block">Đặt lịch khám bệnh viện</Link>
  </main>
}
