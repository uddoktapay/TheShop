<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Models\CombinedOrder;
use App\Library\UddoktaPay;
use Illuminate\Http\Request;

class UddoktaPayPaymentController extends Controller
{
    public function index()
    {
        try {
            $paymentData = $this->preparePaymentData();
            
            $uddoktaPay = UddoktaPay::make(
                config('uddoktapay.api_key'),
                config('uddoktapay.api_url')
            );

            $paymentUrl = $uddoktaPay->initPayment($paymentData);
            
            return redirect($paymentUrl);
            
        } catch (\Exception $e) {
            flash(translate('Payment initialization failed: ' . $e->getMessage()))->error();
            return redirect()->route('home');
        }
    }

    private function preparePaymentData(): array
    {
        $user = auth()->user();
        $metadata = [];
        
        if (session('payment_type') == 'cart_payment' || session('payment_type') == 'repayment') {
            $order = CombinedOrder::where('code', session('order_code'))->first();
            
            if (!$order) {
                throw new \Exception('Order not found');
            }

            $metadata = [
                'payment_type' => session('payment_type'),
                'order_code' => session('order_code'),
                'redirect_to' => session('redirect_to'),
            ];

            return [
                'full_name' => $user->name ?? $order->user->name ?? 'Guest',
                'email' => $user->email ?? $order->user->email ?? 'guest@example.com',
                'amount' => $order->grand_total,
                'metadata' => $metadata,
                'return_type' => 'GET',
                'redirect_url' => route('uddoktapay.success'),
                'cancel_url' => route('uddoktapay.cancel'),
            ];
            
        } elseif (session('payment_type') == 'wallet_payment') {
            $metadata = [
                'payment_type' => 'wallet_payment',
                'user_id' => session('user_id'),
                'amount' => session('amount'),
                'redirect_to' => session('redirect_to'),
            ];

            return [
                'full_name' => $user->name ?? 'User',
                'email' => $user->email ?? 'user@example.com',
                'amount' => session('amount'),
                'metadata' => $metadata,
                'return_type' => 'GET',
                'redirect_url' => route('uddoktapay.success'),
                'cancel_url' => route('uddoktapay.cancel'),
            ];
            
        } elseif (session('payment_type') == 'seller_package_payment') {
            $metadata = [
                'payment_type' => 'seller_package_payment',
                'user_id' => session('user_id'),
                'seller_package_id' => session('seller_package_id'),
                'amount' => session('amount'),
                'redirect_to' => session('redirect_to'),
            ];

            return [
                'full_name' => $user->name ?? 'Seller',
                'email' => $user->email ?? 'seller@example.com',
                'amount' => session('amount'),
                'metadata' => $metadata,
                'return_type' => 'GET',
                'redirect_url' => route('uddoktapay.success'),
                'cancel_url' => route('uddoktapay.cancel'),
            ];
        }

        throw new \Exception('Invalid payment type');
    }

    public function success(Request $request)
    {
        try {
            $invoiceId = $request->input('invoice_id');
            
            if (!$invoiceId) {
                throw new \Exception('Invoice ID not found');
            }

            $uddoktaPay = UddoktaPay::make(
                config('uddoktapay.api_key'),
                config('uddoktapay.api_url')
            );

            $verification = $uddoktaPay->verifyPayment($invoiceId);

            if (isset($verification['status']) && $verification['status'] === 'COMPLETED') {
                return (new PaymentController)->payment_success($verification);
            }

            throw new \Exception('Payment not completed');
            
        } catch (\Exception $e) {
            flash(translate('Payment verification failed: ' . $e->getMessage()))->error();
            return (new PaymentController)->payment_failed();
        }
    }

    public function cancel(Request $request)
    {
        flash(translate('Payment cancelled.'))->warning();
        return (new PaymentController)->payment_failed();
    }
}
