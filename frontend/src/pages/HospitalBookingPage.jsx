import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { hospitalApi } from '@/api/hospitalApi'
import { fetchPatientProfiles } from '@/api/profileApi'
import { useAuth } from '@/context/AuthContext'
import { Button } from '@/components/ui/button'
import { formatCurrency, formatDateVN } from '@/lib/mockData'

export default function HospitalBookingPage() {
  const { id } = useParams()
  const { user } = useAuth()
  const navigate = useNavigate()
  const [hospital, setHospital] = useState(null)
  const [profiles, setProfiles] = useState([])
  const [examId, setExamId] = useState('')
  const [profileId, setProfileId] = useState('')
  const [date, setDate] = useState('')
  const [shift, setShift] = useState('morning')
  const [slotId, setSlotId] = useState('')
  const [symptoms, setSymptoms] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let active = true
    hospitalApi.detail(id).then((res) => {
      if (active) setHospital(res.data)
    }).catch((err) => { if (active) setError(err.message) })
    return () => { active = false }
  }, [id])
  useEffect(() => {
    if (!user) return
    let active = true
    fetchPatientProfiles().then((res) => { if (active) setProfiles(res.data) })
      .catch((err) => { if (active) setError(err.message) })
    return () => { active = false }
  }, [user])

  const exam = hospital?.exam_types.find((item) => item.id === Number(examId))
  const dates = [...new Set(hospital?.slots.map((slot) => slot.work_date) || [])]
  const slots = hospital?.slots.filter((slot) => slot.work_date === date && (shift === 'morning' ? slot.start_time < '12:00' : slot.start_time >= '13:00')) || []
  const remaining = hospital?.shift_availability.find((item) => item.department_id === exam?.department?.id && item.date === date && item.shift === shift)?.remaining

  async function submit(event) {
    event.preventDefault()
    if (!user) { navigate(`/login?redirect=${encodeURIComponent(`/hospitals/${id}`)}`); return }
    setBusy(true); setError('')
    try {
      const result = await hospitalApi.book({ hospital_id: Number(id), exam_type_id: Number(examId), patient_profile_id: Number(profileId), slot_id: Number(slotId), symptoms: symptoms || null })
      navigate(`/payment?booking_id=${result.data.id}`)
    } catch (err) {
      setError(err.message)
      // Reload capacity after a conflict; a second submit is still checked by the server.
      hospitalApi.detail(id).then((res) => setHospital(res.data)).catch(() => {})
      setSlotId('')
    } finally { setBusy(false) }
  }

  return <main className="max-w-3xl mx-auto p-6 space-y-5">
    <Link to="/hospitals" className="text-sky-700">← Danh sách bệnh viện</Link>
    <h1 className="text-3xl font-bold">{hospital?.name || 'Đặt lịch khám bệnh viện'}</h1>
    <p>{hospital?.address}</p>
    {(user?.role === 'admin' || (user?.role === 'hospital_admin' && Number(user.hospital_id) === Number(id))) && <Link className="block text-sky-700" to={`/hospitals/${id}/manage`}>Quản lý sức chứa, dặn dò và tiếp nhận</Link>}
    {error && <p role="alert" className="p-3 bg-red-50 text-red-700 rounded-lg">{error}</p>}
    {hospital && <form onSubmit={submit} className="space-y-5 bg-white border rounded-2xl p-6">
      <label className="block font-medium">Chuyên khoa / Dịch vụ
        <select required value={examId} onChange={(e) => { setExamId(e.target.value); setSlotId('') }} className="block w-full border rounded-lg p-3 mt-2">
          <option value="">Chọn chuyên khoa</option>
          {hospital.exam_types.map((item) => <option key={item.id} value={item.id}>{item.department?.name} — {item.name}</option>)}
        </select>
      </label>
      <p className="text-sm text-slate-600">Đặt trước từ 1 đến 30 ngày. Ca sáng 07:00–11:30; ca chiều 13:00–16:30. Mỗi khung giờ tiếp nhận dài 30 phút.</p>
      <div className="grid sm:grid-cols-2 gap-4">
        <label>Ngày khám<select required value={date} onChange={(e) => { setDate(e.target.value); setSlotId('') }} className="block w-full border rounded-lg p-3 mt-2"><option value="">Chọn ngày</option>{dates.map((value) => <option key={value} value={value}>{formatDateVN(value)}</option>)}</select></label>
        <label>Ca khám<select value={shift} onChange={(e) => { setShift(e.target.value); setSlotId('') }} className="block w-full border rounded-lg p-3 mt-2"><option value="morning">Sáng</option><option value="afternoon">Chiều</option></select></label>
      </div>
      <fieldset><legend className="mb-2">Giờ tiếp nhận dự kiến</legend><div className="flex flex-wrap gap-2">{slots.map((slot) => <label key={slot.id} className={`border rounded-lg p-3 cursor-pointer ${Number(slotId) === slot.id ? 'bg-sky-100 border-sky-600' : ''}`}>
        <input type="radio" name="slot" required disabled={remaining === 0} value={slot.id} checked={Number(slotId) === slot.id} onChange={(e) => setSlotId(e.target.value)} className="mr-2" />{slot.start_time.slice(0, 5)} ({Math.min(slot.capacity - slot.booked_count, remaining ?? slot.capacity)} chỗ)
      </label>)}</div>{slots.length === 0 && <p className="text-slate-600">Chưa có khung giờ khả dụng. Vui lòng chọn ca hoặc ngày khác.</p>}</fieldset>
      {remaining === 0 && <p role="status" className="text-amber-800">Ca khám đã hết chỗ. Vui lòng chọn ca hoặc ngày khác.</p>}
      {user ? <label className="block">Hồ sơ người khám<select required value={profileId} onChange={(e) => setProfileId(e.target.value)} className="block w-full border rounded-lg p-3 mt-2"><option value="">Chọn hồ sơ</option>{profiles.map((profile) => <option key={profile.id} value={profile.id}>{profile.full_name}</option>)}</select><Link to="/my-bookings?tab=profiles" className="text-sm text-sky-700">Thêm / quản lý hồ sơ người bệnh</Link></label> : <Link to={`/login?redirect=${encodeURIComponent(`/hospitals/${id}`)}`} className="text-sky-700">Đăng nhập để chọn hồ sơ và đặt lịch</Link>}
      <label className="block">Lý do khám (không bắt buộc)<textarea maxLength={500} value={symptoms} onChange={(e) => setSymptoms(e.target.value)} className="block border rounded-lg p-3 mt-2 w-full" /></label>
      {exam && <div className="space-y-2 border-t pt-4"><p>Phí khám: {formatCurrency(exam.price)}</p><p>Phí tiện ích: {formatCurrency(exam.service_fee)}</p><p className="font-bold">Tổng: {formatCurrency(exam.price + exam.service_fee)}</p></div>}
      <p className="text-sm">Giữ chỗ tối đa 15 phút để thanh toán. Phiếu khám và mã QR được cấp sau khi thanh toán thành công.</p>
      <Button type="submit" disabled={busy || remaining === 0 || !hospital.accepting_bookings || !user}>{busy ? 'Đang giữ chỗ…' : 'Tiếp tục thanh toán'}</Button>
    </form>}
  </main>
}
