<?php

namespace Modules\Payment\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;
use Modules\Payment\Entities\Order;
use PayOS\PayOS;

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */

    private string $payOSClientId;
    private string $payOSApiKey;
    private string $payOSChecksumKey;

    public function getPaymentLinkInfoOfOrder(string $id)
    {
        $payOS = new PayOS($this->payOSClientId, $this->payOSApiKey, $this->payOSChecksumKey);
        try {
            $response = $payOS->getPaymentLinkInformation($id);
            return response()->json([
                "error" => 0,
                "message" => "Success",
                "data" => $response["data"]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "error" => $th->getCode(),
                "message" => $th->getMessage(),
                "data" => null
            ]);
        }
    }

    public function cancelPaymentLinkOfOrder(Request $request, string $id)
    {
        $body = json_decode($request->getContent(), true);
        $payOS = new PayOS($this->payOSClientId, $this->payOSApiKey, $this->payOSChecksumKey);
        try {
            $cancelBody = is_array($body) && $body["cancellationReason"] ? $body : null;
            $response = $payOS->cancelPaymentLink($id, $cancelBody);
            return response()->json([
                "error" => 0,
                "message" => "Success",
                "data" => $response["data"]
            ]);
        } catch (\Throwable $th) {
            return response()->json([
                "error" => $th->getCode(),
                "message" => $th->getMessage(),
                "data" => null
            ]);
        }
    }
}
