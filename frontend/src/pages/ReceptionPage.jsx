import { useState } from 'react'
import { Link } from 'react-router-dom'
import { hospitalApi } from '@/api/hospitalApi'
import { useAuth } from '@/context/AuthContext'
import { Button } from '@/components/ui/button'

export default function ReceptionPage() {
  const { user } = useAuth()
  const [token, setToken] = useState('')
  const [ticket, setTicket] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  if (!['admin', 'staff', 'hospital_admin', 'kiosk'].includes(user?.role)) return <p className="p-8">Chỉ nhân viên tiếp đón và Kiosk được sử dụng chức năng này.</p>

  async function checkIn(event) {
    event.preventDefault(); setBusy(true); setError(''); setTicket(null)
    try { const res = await hospitalApi.checkIn(token.trim()); setTicket(res.data); setToken('') }
    catch (err) { setError(err.message) }
    finally { setBusy(false) }
  }
  async function complete() {
    setBusy(true); setError('')
    try { const res = await hospitalApi.complete(ticket.id); setTicket(res.data) }
    catch (err) { setError(err.message) }
    finally { setBusy(false) }
  }
  return <main className="max-w-2xl mx-auto p-6 space-y-5">
    <h1 className="text-3xl font-bold">Tiếp đón tại bệnh viện</h1>
    {['admin', 'hospital_admin'].includes(user.role) && <Link className="block text-sky-700" to={user.hospital_id ? `/hospitals/${user.hospital_id}/manage` : '/hospitals'}>Quản lý bệnh viện</Link>}
    <p>Quét mã QR bằng máy quét hoặc nhập nội dung mã QR. Chỉ tiếp nhận phiếu đúng ngày hẹn.</p>
    <form onSubmit={checkIn} className="space-y-3"><label className="block">Nội dung QR<input autoFocus required value={token} onChange={(e) => setToken(e.target.value)} autoComplete="off" className="block border rounded-lg p-3 w-full mt-2" /></label><Button disabled={busy} type="submit">{busy ? 'Đang xác thực…' : 'Xác nhận check-in'}</Button></form>
    {error && <p role="alert" className="bg-red-50 text-red-700 p-3 rounded-lg">{error}</p>}
    {ticket && <section className="p-6 bg-white border rounded-xl space-y-3"><h2 className="text-xl font-bold">{ticket.status === 'completed' ? 'Đã hoàn tất khám' : 'Check-in thành công'}</h2><p>{ticket.patient.full_name} · {ticket.ticket_code}</p><p className="text-3xl font-bold">{ticket.queue_number}</p><p>{ticket.specialty} · {ticket.room_number || 'Liên hệ quầy để được phân phòng'}</p><p>Tiếp nhận lúc: {new Date(ticket.check_in_at).toLocaleString('vi-VN')}</p>{ticket.status === 'checked_in' && user.role !== 'kiosk' && <Button disabled={busy} onClick={complete}>Xác nhận đã hoàn tất khám</Button>}</section>}
  </main>
}
