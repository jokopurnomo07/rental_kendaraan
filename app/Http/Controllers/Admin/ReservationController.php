<?php

namespace App\Http\Controllers\Admin;

use Midtrans\Snap;
use App\Models\Reservation;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Mail\NotificationMail;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\VehicleAvailabilityNotification;
use App\Mail\ReservationRejectionNotification;

class ReservationController extends Controller
{
    public function index($status){
        
        if( $status == "pending" ){
            $status = ['pending'];
        }elseif( $status == "canceled" ){
            $status = ['canceled', 'rejected'];
        }else{
            $status = ['confirmed'];
        }

        $reservation = Reservation::whereIn('status', $status)->orderBy('created_at', 'DESC')->get();
        $reservation->loadMissing([
            'user:id,name,email,phone,address',
            'vehicle',
            'rental_package',
        ]);
        $reservation->where('type', 'car')->load('services');
        

        
        $notifications = Notification::where('is_read', false)->get();
        return view('admin.reservation.index', [
            'reservation' => $reservation,
            'notifications' => $notifications
        ]);
    }

    public function show($id){
        $reservation = Reservation::findOrFail($id);
        $reservation->loadMissing(['user:id,name,email,phone,address', 'services', 'vehicle', 'rental_package']);
        
        return view('admin.reservation.show', compact('reservation'));
    }

    public function createPayment($reservation)
    {
        // Example data (you should get this from the reservation details)
        $orderId = uniqid(); // Generate a unique order ID
        $amount = $reservation->total_price; // Total payment amount

        $params = [
            'transaction_details' => [
                'order_id' => $reservation->trx_id,
                'gross_amount' => $amount,
            ],
            'customer_details' => [
                'first_name' => $reservation->user != null ? $reservation->user->name : $reservation->nama_guest,
                'email' => $reservation->user != null ? $reservation->user->email : $reservation->email_guest,
                'phone' => $reservation->user != null ? $reservation->user->phone : $reservation->no_hp_guest,
            ],
            'item_details' => [
                [
                    'id' => $reservation->vehicle_id,
                    'price' => $amount,
                    'quantity' => 1,
                    'name' => $reservation->vehicle->name,
                ]
            ],
            'callbacks' => [
                'finish' => route('midtrans.notification'), // Optional: specify finish URL
            ],
        ];

        try {
            // Generate Snap token
            $snapToken = Snap::getSnapToken($params);

            // Return the payment URL to be included in the email
            $paymentUrl = Snap::createTransaction($params)->redirect_url;

            // Store or return $paymentUrl as neededx
            return $paymentUrl;
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    public function updateStatus(Request $request){
        $reservation = Reservation::find($request->id);
        $reservation->loadMissing(['user:id,name,email,phone,address', 'services', 'vehicle']);
        if ($reservation) {
            $reservation->status = $request->status;

            if ($request->status == 'rejected' || $request->status == "canceled") {
                $reservation->reason_canceled = $request->reason;
                $status = $request->status == "rejected" ? "Penolakan" : "Pembatalan"; 
                Mail::to($reservation->user_id != null ? $reservation->user->email : $reservation->email_guest)->send(new ReservationRejectionNotification($reservation, $status));
            }
            
            if( $request->status == "confirmed" ){
                $paymentUrl = $this->createPayment($reservation);
                Mail::to($reservation->user_id != null ? $reservation->user->email : $reservation->email_guest)->send(new VehicleAvailabilityNotification($reservation, $paymentUrl));
            }
            
            $reservation->save();
            return response()->json(['success' => true]);
        }

        return response()->json(['success' => false]);
    }

}
