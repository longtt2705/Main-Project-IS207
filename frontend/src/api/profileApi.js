import api from './axiosClient'

export const fetchUserBookings = ({ status = 'all', q = '', page = 1, per_page = 20 } = {}) =>
  api.get('/bookings', { params: { status, q, page, per_page } })
export const cancelUserBooking = (id, reason = '') => api.patch(`/bookings/${id}/cancel`, { reason })
export const fetchPatientProfiles = () => api.get('/patient-profiles')
export const createPatientProfile = (data) => api.post('/patient-profiles', data)
export const updatePatientProfile = (id, data) => api.put(`/patient-profiles/${id}`, data)
export const deletePatientProfile = (id) => api.delete(`/patient-profiles/${id}`)
export const fetchCurrentUser = () => api.get('/user')
export const updateCurrentUser = (data) => api.put('/user', data)
export const logoutUser = () => api.post('/auth/logout')
