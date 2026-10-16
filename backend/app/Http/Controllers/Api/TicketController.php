<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TicketResource;
use App\Models\Booking;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function show(Request $request, int $booking, TicketService $tickets)
    {
        $reference = Booking::where('user_id', $request->user()->id)->findOrFail($booking);
        $tickets->expireUnused();
        $ticket = $reference->ticket()->firstOrFail();

        return response()->json(['success' => true, 'data' => new TicketResource($ticket)]);
    }

    public function checkIn(Request $request, TicketService $tickets)
    {
        $data = $request->validate(['qr_token' => ['required', 'string', 'max:2000']]);
        $payload = $tickets->verify($data['qr_token']);
        $ticket = Ticket::where('ticket_code', $payload['tid'])->firstOrFail();
        $this->authorizeStaff($request, $ticket);

        return response()->json(['success' => true, 'message' => 'Check-in thành công.', 'data' => new TicketResource($tickets->checkIn($ticket, $data['qr_token']))]);
    }

    public function complete(Request $request, Ticket $ticket, TicketService $tickets)
    {
        $this->authorizeStaff($request, $ticket);
        abort_if($request->user()->role === 'kiosk', 403);

        return response()->json(['success' => true, 'data' => new TicketResource($tickets->complete($ticket))]);
    }

    private function authorizeStaff(Request $request, Ticket $ticket): void
    {
        $user = $request->user();
        abort_unless($user->role === 'admin' || (
            in_array($user->role, ['staff', 'hospital_admin', 'kiosk'], true)
            && (int) $user->hospital_id === (int) $ticket->booking->hospital_id
        ), 403, 'Bạn không có quyền tiếp đón tại bệnh viện này.');
    }
}
