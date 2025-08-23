<?php

namespace App\Services;

use App\Models\Partner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuthEntityService
{
    /**
     * Get the authenticated user's corresponding model
     * based on their role.
     * (e.g., partner_id, admin_id, client_id, etc.)
     *
     * @return object
     */
    public function getAuthenticatedUser(): ?object
    {
        $user = Auth::guard('sanctum')->user();

        if (!$user) {
            return null;
        }

        return match ($user->role) {
            'partner'  => \App\Models\Partner::where('user_id', $user->id)->first(),
            'employee' => \App\Models\Employee::where('user_id', $user->id)->first(),
            'delivery_person' => \App\Models\DeliveryPerson::where('user_id', $user->id)->first(),
            'admin' => \App\Models\User::find($user->id),

            default    => \App\Models\User::find($user->id),
        };
    }

    /***
     * get user by id
     * @param int $id
     */
    public function getUserById(int $id): ?object
    {

        $user = DB::table('user')->where('id', $id)->first();

        if (!$user) {
            return null;
        }

        return match ($user->role) {
            'partner'  => \App\Models\Partner::where('user_id', $user->id)->first(),
            'employee' => \App\Models\Employee::where('user_id', $user->id)->first(),
            'delivery_person' => \App\Models\DeliveryPerson::where('user_id', $user->id)->first(),
            'admin' => \App\Models\User::find($user->id),

            default    => \App\Models\User::find($user->id),
        };
    }

    public function getPartnerById(int $id): ?object
    {
        $partner = Partner::find($id);

        if (!$partner) {
            return null;
        }

        return $partner;
    }

    public function getPartnerByUserId(int $userId): ?object
    {
        $partner = Partner::where('user_id', $userId)->first();

        if (!$partner) {
            return null;
        }

        return $partner;
    }
}
