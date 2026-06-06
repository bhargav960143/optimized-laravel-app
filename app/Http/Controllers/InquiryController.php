<?php

namespace App\Http\Controllers;

use App\Models\Inquiry;
use Illuminate\Http\Request;

class InquiryController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'             => 'required|string|max:100',
            'email'            => 'required|email|max:150',
            'phone'            => 'required|string|max:20',
            'trip_type'        => 'required|in:oneway,roundtrip,airport_pickup,airport_drop,sightseen,tour_package',
            'pickup_location'  => 'required|string|max:200',
            'drop_location'    => 'nullable|string|max:200',
            'pickup_date'      => 'required|date|after_or_equal:today',
            'return_date'      => 'nullable|date|after:pickup_date',
            'passengers'       => 'required|integer|min:1|max:50',
            'vehicle_type'     => 'required|in:sedan,suv,tempo_traveller,bus',
            'notes'            => 'nullable|string|max:1000',
        ]);

        Inquiry::create($data);

        // XHR request from Alpine.js fetch()
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json(['message' => 'Inquiry submitted successfully.'], 201);
        }

        return redirect('/')->with('success', 'Inquiry submitted! We will contact you shortly.');
    }
}
