<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index()
    {
        return Booking::with(['property', 'agent', 'client'])->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'property_id' => 'required|exists:properties,id',
            'agent_id' => 'required|exists:users,id',
            'client_id' => 'nullable|exists:users,id',
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'note' => 'nullable|string',
        ]);

        $booking = Booking::create($validated);
        return response()->json($booking, 201);
    }
}
