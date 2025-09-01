<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Google\Auth\HttpHandler\HttpHandlerFactory;

class NotificationService
{
    public static function sendNotification($title, $body, $url, $usersTokens)
    {
        try {
            // Retrieve the authenticated user using Sanctum
            // $user = Auth::guard('sanctum')->user();

            // $query = DB::table('user_tokens');
            // if ($user) {
            //     $query->where('id', '<>', $user->userId);
            // }
            // $tokens = $query->pluck('token');

            $credentialsPath = storage_path('app/firebase/pvKey.json');
            $credential = new \Google\Auth\Credentials\ServiceAccountCredentials(
                "https://www.googleapis.com/auth/firebase.messaging",
                json_decode(file_get_contents($credentialsPath), true)
            );

            $token = $credential->fetchAuthToken(\Google\Auth\HttpHandler\HttpHandlerFactory::build());

            foreach ($usersTokens as $deviceToken) {
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $token['access_token'],
                ])->post("https://fcm.googleapis.com/v1/projects/lv-manager/messages:send", [
                    "message" => [
                        "token" => $deviceToken,
                        "notification" => [
                            "title" => $title,
                            "body" => $body,
                            "image" => asset('https://jibouli.lvmanager.net/jibouli-icon.ico'),
                        ],
                        "webpush" => [
                            "fcm_options" => [
                                "link" => url($url),
                            ]
                        ]
                    ]
                ]);

                // Handle the response if needed
                if ($response->failed()) {
                    // Log or handle the error
                    Log::error('FCM Notification Error', [
                        'device_token' => $deviceToken,
                        'response' => $response->body(),
                    ]);
                }
            }
        } catch (\Throwable $th) {
            // Handle the error or log it
            throw $th;
        }
    }

    public static function getUserFullName()
    {
        try {
            // Retrieve the authenticated user using Sanctum
            $user = Auth::guard('sanctum')->user();

            if (!$user) {
                throw new \Exception('User is not authenticated.');
            }

            $userName = DB::table('user')
                ->where('id', $user->id)
                ->value('name');


            if (!$userName) {
                throw new \Exception('No user with this ID.');
            }

            return $userName;
        } catch (\Throwable $th) {
            // Handle the error or log it
            throw $th;
        }
    }

    public static function getDeliveryDriversTokens($excludeDrivers = [])
    {
        try {
            $query = DB::table('users_tokens')
                ->join('delivery_person', 'users_tokens.user_id', '=', 'delivery_person.user_id')
                ->where('delivery_person.is_available', true);

            if (!empty($excludeDrivers)) {
                $query->whereNotIn('delivery_person.id', $excludeDrivers);
            }

            $tokens = $query->pluck('users_tokens.token')
                ->toArray();

            return $tokens;
        } catch (\Throwable $th) {
            // Handle the error or log it
            throw $th;
        }
    }

    public function getAdminsTokens()
    {
        try {
            $query = DB::table('users_tokens')
                ->join('user', 'users_tokens.user_id', '=', 'user.id')
                ->where('users_tokens.role', 'admin');
            $tokens = $query->pluck('users_tokens.token')
                ->toArray();
            return $tokens;
        } catch (\Throwable $th) {
            // Handle the error or log it
            throw $th;
        }
    }
}