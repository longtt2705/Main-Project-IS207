import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import api from '@/api/axiosClient'
import { hospitalApi } from '@/api/hospitalApi'
import { useAuth } from '@/context/AuthContext'
import { Button } from '@/components/ui/button'

function DepartmentForm({ department, onMessage }) {
  const [busy, setBusy] = useState(false)
  async function save(event) {
    event.preventDefault(); setBusy(true)
    const data = new FormData(event.currentTarget)
    try {
      await api.patch(`/hospital-departments/${department.id}`, {
        max_shift_capacity: Number(data.get('capacity')), instructions: data.get('instructions'), room_number: data.get('room'),
      })
      onMessage('Đã lưu cấu hình chuyên khoa.')
    } catch (err) { onMessage(err.message) }
    finally { setBusy(false) }
  }
  return <form onSubmit={save} className="p-5 border rounded-xl bg-white space-y-3">
    <h2 className="text-xl font-bold">{department.name}</h2>
    <label className="block">Sức chứa tối đa mỗi ca<input type="number" min="1" max="10000" required name="capacity" defaultValue={department.max_shift_capacity} className="block border rounded-lg p-2 w-full mt-1" /></label>
    <label className="block">Phòng khám<input name="room" maxLength={50} defaultValue={department.room_number || ''} className="block border rounded-lg p-2 w-full mt-1" /></label>
    <label className="block">Dặn dò chuyên khoa<textarea name="instructions" maxLength={500} defaultValue={department.instructions || ''} className="block border rounded-lg p-2 w-full mt-1" /></label>
    <Button disabled={busy}>Lưu cấu hình</Button>
  </form>
}

export default function HospitalAdminPage() {
  const { id } = useParams()
  const { user } = useAuth()
  const [hospital, setHospital] = useState(null)
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)
  const allowed = user.role === 'admin' || (user.role === 'hospital_admin' && Number(user.hospital_id) === Number(id))
  useEffect(() => {
    let active = true
    hospitalApi.detail(id).then((res) => { if (active) setHospital(res.data) }).catch((err) => { if (active) setMessage(err.message) })
    return () => { active = false }
  }, [id])
  if (!allowed) return <p className="p-8">Bạn không có quyền quản lý bệnh viện này.</p>
  const departments = [...new Map(hospital?.exam_types.filter((item) => item.department).map((item) => [item.department.id, item.department]) || []).values()]
  async function createSlot(event) {
    event.preventDefault(); setBusy(true)
    const data = new FormData(event.currentTarget)
    try {
      await api.post(`/hospitals/${id}/slots`, { work_date: data.get('date'), start_time: data.get('time'), capacity: Number(data.get('capacity')) })
      setMessage('Đã tạo khung giờ tiếp nhận 30 phút.')
    } catch (err) { setMessage(err.message) }
    finally { setBusy(false) }
  }
  async function changeAvailability(event) {
    event.preventDefault()
    const reason = new FormData(event.currentTarget).get('reason')
    if (hospital.accepting_bookings && !window.confirm('Ngừng tiếp nhận sẽ hủy các lịch chưa check-in sắp tới và yêu cầu hoàn cả phí khám lẫn phí tiện ích. Tiếp tục?')) return
    setBusy(true)
    try {
      const result = await api.put(`/hospitals/${id}/availability`, { accepting_bookings: !hospital.accepting_bookings, reason })
      setHospital({ ...hospital, accepting_bookings: result.data.accepting_bookings })
      setMessage(`Đã cập nhật tiếp nhận. Số lịch đã hủy: ${result.data.cancelled_count}.`)
    } catch (err) { setMessage(err.message) }
    finally { setBusy(false) }
  }
  return <main className="max-w-3xl mx-auto p-6 space-y-5">
    <Link to={`/hospitals/${id}`} className="text-sky-700">← Bệnh viện</Link>
    <h1 className="text-3xl font-bold">Quản lý tiếp nhận · {hospital?.name}</h1>
    {message && <p role="status" className="p-3 border rounded-lg">{message}</p>}
    {departments.map((department) => <DepartmentForm key={department.id} department={department} onMessage={setMessage} />)}
    <form onSubmit={createSlot} className="border rounded-xl p-5 space-y-3 bg-white">
      <h2 className="text-xl font-bold">Thêm khung giờ tiếp nhận</h2>
      <p>Đặt trước từ 1 đến 30 ngày; khung giờ dài 30 phút trong ca sáng hoặc chiều.</p>
      <label className="block">Ngày khám<input type="date" name="date" required className="block border rounded-lg p-2 mt-1" /></label>
      <label className="block">Giờ bắt đầu<input type="time" step="1800" name="time" required className="block border rounded-lg p-2 mt-1" /></label>
      <label className="block">Số chỗ trong khung giờ<input type="number" min="1" max="10000" name="capacity" required defaultValue="10" className="block border rounded-lg p-2 mt-1" /></label>
      <Button disabled={busy}>Tạo khung giờ</Button>
    </form>
    {hospital && <form onSubmit={changeAvailability} className="border rounded-xl p-5 space-y-3">
      <h2 className="text-xl font-bold">{hospital.accepting_bookings ? 'Tạm ngừng tiếp nhận' : 'Mở lại tiếp nhận'}</h2>
      <p>Ngừng tiếp nhận sẽ hủy lịch sắp tới chưa check-in và gửi yêu cầu hoàn toàn bộ tiền, bao gồm phí tiện ích.</p>
      <label className="block">Lý do<input required name="reason" maxLength={500} className="block border rounded-lg p-2 w-full mt-1" /></label>
      <Button disabled={busy}>{hospital.accepting_bookings ? 'Ngừng tiếp nhận và hủy lịch' : 'Mở lại tiếp nhận'}</Button>
    </form>}
  </main>
}
