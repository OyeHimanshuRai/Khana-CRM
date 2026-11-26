<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Models\Shop;
use App\Services\OnlineBookingService;
use App\Services\PaymentIntentService;
use App\Support\ApiResponse;
use App\Support\OnlineBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;

// "Book a table" on the storefront: pick a time, pay the advance if the restaurant asks for one, see and cancel bookings.
class BookingController extends Controller
{
    public function __construct(
        private readonly OnlineBookingService $bookings,
        private readonly PaymentIntentService $payments,
    ) {}

    public function create(Shop $shop): View
    {
        $settings = OnlineBooking::settings($shop);
        $today = OnlineBooking::now($shop)->startOfDay();
        $customer = Auth::guard('customer')->user();

        // Signing in from here comes straight back to the booking page.
        if ($customer === null) {
            session()->put('url.intended', route('shop.book', $shop));
        }

        return view('shop.booking.create', [
            'open' => OnlineBooking::isOpen($shop),
            'settings' => $settings,
            'today' => $today->toDateString(),
            'lastDate' => $today->copy()->addDays((int) $settings['window_days'])->toDateString(),
            'customer' => $customer,
            'checkoutScript' => (string) config('payments.gateways.'.config('payments.gateway').'.checkout_js'),
        ]);
    }

    public function slots(Request $request, Shop $shop): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'party_size' => ['required', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $slots = $this->bookings->slots($shop, $data['date'], (int) $data['party_size']);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        $settings = OnlineBooking::settings($shop);

        return ApiResponse::success('', [
            'slots' => $slots,
            'deposit' => OnlineBooking::depositFor($settings, (int) $data['party_size']),
        ]);
    }

    public function store(Request $request, Shop $shop): JsonResponse
    {
        $request->merge(['mobile' => preg_replace('/[\s-]/', '', (string) $request->input('mobile'))]);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'party_size' => ['required', 'integer', 'min:1', 'max:50'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'mobile' => ['required', 'regex:/^\+?[0-9]{10,15}$/'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'mobile.regex' => 'Please enter a valid mobile number.',
        ]);

        try {
            [$reservation, $intent] = $this->bookings->hold($shop, Auth::guard('customer')->user(), $data);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), [], 409);
        }

        if ($intent === null) {
            return ApiResponse::success('Your table is booked.', ['paid' => true], route('shop.bookings.show', [$shop, $reservation->id]));
        }

        return ApiResponse::success('Table held. Complete the payment to confirm.', $this->checkout($shop, $reservation, $intent));
    }

    public function pay(Shop $shop, int $booking): JsonResponse
    {
        $reservation = $this->mine($shop, $booking);

        try {
            $intent = $this->bookings->retry($reservation);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), [], 409);
        }

        return ApiResponse::success('', $this->checkout($shop, $reservation, $intent));
    }

    public function confirm(Request $request, Shop $shop, int $booking): JsonResponse
    {
        $reservation = $this->mine($shop, $booking);
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:64'],
            'razorpay_order_id' => ['nullable', 'string', 'max:120'],
            'razorpay_payment_id' => ['nullable', 'string', 'max:120'],
            'razorpay_signature' => ['nullable', 'string', 'max:255'],
        ]);

        // Found through this booking too, so another booking's payment can never confirm this one.
        $intent = $this->intent($reservation, $data['reference']);
        if ($intent === null) {
            return ApiResponse::error('That payment does not belong to this booking.', [], 404);
        }

        try {
            $this->payments->confirmFromBrowser($intent, $data);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage());
        }

        $reservation->refresh();
        if ($reservation->status !== Reservation::CONFIRMED) {
            return ApiResponse::error($reservation->outcome_note ?: 'The booking could not be confirmed. Any payment will be refunded.');
        }

        return ApiResponse::success('Payment received. Your table is booked.', [], route('shop.bookings.show', [$shop, $reservation->id]));
    }

    public function failed(Request $request, Shop $shop, int $booking): JsonResponse
    {
        $reservation = $this->mine($shop, $booking);
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $intent = $this->intent($reservation, $data['reference']);
        if ($intent === null) {
            return ApiResponse::error('That payment does not belong to this booking.', [], 404);
        }

        $this->payments->fail($intent, $data['reason'] ?: 'The payment did not go through.');

        return ApiResponse::success('', ['hold_expires_at' => $reservation->hold_expires_at?->toIso8601String()]);
    }

    public function index(Shop $shop): View
    {
        $reservations = Reservation::allShops()
            ->where('shop_id', $shop->id)
            ->where('customer_id', Auth::guard('customer')->id())
            ->where('source', 'online')
            ->orderByDesc('reserved_for')
            ->paginate(15);

        return view('shop.booking.index', ['reservations' => $reservations, 'settings' => OnlineBooking::settings($shop)]);
    }

    public function show(Shop $shop, int $booking): View
    {
        $reservation = $this->mine($shop, $booking);

        return view('shop.booking.show', [
            'reservation' => $reservation,
            'settings' => OnlineBooking::settings($shop),
            'payments' => $this->bookings->intents($reservation),
            'checkoutScript' => (string) config('payments.gateways.'.config('payments.gateway').'.checkout_js'),
        ]);
    }

    public function cancel(Shop $shop, int $booking): JsonResponse|RedirectResponse
    {
        $reservation = $this->mine($shop, $booking);

        try {
            $refunded = $this->bookings->cancelByGuest($reservation);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), [], 409);
        }

        return ApiResponse::success(
            $refunded ? 'Booking cancelled. Your advance has been refunded.' : 'Booking cancelled.',
            [],
            route('shop.bookings.show', [$shop, $reservation->id]),
        );
    }

    /* ------------------------------------------------------------ helpers */

    // Only the signed-in guest's own online booking at this outlet.
    private function mine(Shop $shop, int $id): Reservation
    {
        return Reservation::allShops()
            ->where('shop_id', $shop->id)
            ->where('customer_id', Auth::guard('customer')->id())
            ->where('source', 'online')
            ->findOrFail($id);
    }

    private function intent(Reservation $reservation, string $reference): ?PaymentIntent
    {
        return PaymentIntent::allShops()
            ->where('reference', $reference)
            ->where('payable_type', $reservation->getMorphClass())
            ->where('payable_id', $reservation->id)
            ->first();
    }

    /** @return array<string, mixed> */
    private function checkout(Shop $shop, Reservation $reservation, PaymentIntent $intent): array
    {
        return [
            'paid' => false,
            'booking' => $reservation->id,
            'reference' => $intent->reference,
            'amount' => (float) $intent->amount,
            'checkout' => $this->payments->checkoutFor($intent),
            'hold_expires_at' => $reservation->hold_expires_at?->toIso8601String(),
            'confirm_url' => route('shop.bookings.confirm', [$shop, $reservation->id]),
            'failed_url' => route('shop.bookings.failed', [$shop, $reservation->id]),
            'show_url' => route('shop.bookings.show', [$shop, $reservation->id]),
        ];
    }
}
