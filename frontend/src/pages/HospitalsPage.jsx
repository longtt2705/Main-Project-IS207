import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { hospitalApi } from '@/api/hospitalApi'
import { Button } from '@/components/ui/button'

export default function HospitalsPage() {
  const [params, setParams] = useSearchParams()
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')
  const query = params.toString()
  useEffect(() => {
    let active = true
    hospitalApi.search(Object.fromEntries(new URLSearchParams(query))).then((data) => {
      if (active) { setResult(data); setError('') }
    }).catch((err) => { if (active) setError(err.message) })
    return () => { active = false }
  }, [query])

  return <main className="max-w-5xl mx-auto p-6 space-y-6">
    <h1 className="text-3xl font-bold">Đặt khám bệnh viện</h1>
    <p>Chọn bệnh viện, chuyên khoa và ca khám. Bệnh viện phân công bác sĩ khi tiếp đón.</p>
    <form className="flex flex-wrap gap-3" onSubmit={(event) => {
      event.preventDefault()
      const data = new FormData(event.currentTarget)
      setParams({ q: data.get('q'), city: data.get('city') })
    }}>
      <input name="q" aria-label="Tên bệnh viện hoặc địa chỉ" defaultValue={params.get('q') || ''} placeholder="Tên bệnh viện hoặc địa chỉ" className="border rounded-lg p-3 flex-1" />
      <input name="city" aria-label="Thành phố" defaultValue={params.get('city') || ''} placeholder="Thành phố" className="border rounded-lg p-3" />
      <Button type="submit">Tìm kiếm</Button>
    </form>
    {error && <p role="alert" className="text-red-700">{error}</p>}
    {!result && !error && <p>Đang tải bệnh viện…</p>}
    {result?.data.length === 0 && <p>Không tìm thấy bệnh viện phù hợp.</p>}
    <div className="grid gap-4 md:grid-cols-2">{result?.data.map((hospital) => <article key={hospital.id} className="p-6 border rounded-2xl bg-white space-y-3">
      <h2 className="text-xl font-semibold">{hospital.name}</h2>
      <p>{hospital.address}, {hospital.city}</p>
      <p className="text-sm text-slate-600">{hospital.exam_types.map((exam) => exam.department?.name || exam.name).join(' · ')}</p>
      <Button asChild><Link to={`/hospitals/${hospital.id}`}>Chọn chuyên khoa và lịch khám</Link></Button>
    </article>)}</div>
    {result?.pagination.last_page > 1 && <div className="flex items-center gap-4">
      <Button disabled={result.pagination.current_page <= 1} onClick={() => setParams({ ...Object.fromEntries(params), page: result.pagination.current_page - 1 })}>Trước</Button>
      <span>Trang {result.pagination.current_page} / {result.pagination.last_page}</span>
      <Button disabled={result.pagination.current_page >= result.pagination.last_page} onClick={() => setParams({ ...Object.fromEntries(params), page: result.pagination.current_page + 1 })}>Sau</Button>
    </div>}
  </main>
}
