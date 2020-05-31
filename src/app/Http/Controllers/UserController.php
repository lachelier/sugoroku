<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * ユーザー更新処理
     *
     * @param UpdateUserRequest $request
     * @param User $user
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        // /roomsからのアクセスか判定
        if (url()->previous() != route('rooms') . '/') return redirect()->back();

        $validated = $request->validated();
        $user->update([
            'name' => Arr::get($validated, 'name'),
            'password' => Hash::make(Arr::get($validated, 'new_password')),
        ]);

        return redirect()->route('rooms');
    }
}
