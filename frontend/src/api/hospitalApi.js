import api from './axiosClient'

export const hospitalApi = {
  search: (params) => api.get('/hospitals', { params }),
  detail: (id) => api.get(`/hospitals/${id}`),
  book: (data) => api.post('/bookings/hospital', data),
  ticket: (bookingId) => api.get(`/bookings/${bookingId}/ticket`),
  checkIn: (qr_token) => api.post('/tickets/check-in', { qr_token }),
  complete: (ticketId) => api.post(`/tickets/${ticketId}/complete`),
}
