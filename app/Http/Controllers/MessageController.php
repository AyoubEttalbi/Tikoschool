<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class MessageController extends Controller
{
    /**
     * Store a newly created message in storage and broadcast it.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, User $user)
    {
        // Validate the incoming request
        $request->validate([
            'message' => 'required|string|max:500'
        ]);

        try {
            // Create the message in the database
            $message = Message::create([
                'sender_id' => auth()->id(),
                'recipient_id' => $user->id,
                'message' => $request->message
            ]);

            // Broadcast the event to both sender and recipient
            broadcast(new MessageSent($message, $user->id))->toOthers();

            // Return the created message as a JSON response
            return response()->json([
                'message' => $message->load('sender', 'recipient'),
                'status' => 'success'
            ], 201);
        } catch (\Exception $e) {
            // Log the error and return an appropriate error response
            Log::error('Message send failed: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to send message',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Retrieve all messages between the authenticated user and the specified recipient.
     *
     * @param  \App\Models\User  $user
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(User $user)
    {
        try {
            // Fetch messages between the authenticated user and the specified recipient
            $messages = Message::with(['sender', 'recipient'])
                ->where(function ($query) use ($user) {
                    $query->where('sender_id', Auth::id())
                        ->where('recipient_id', $user->id);
                })
                ->orWhere(function ($query) use ($user) {
                    $query->where('sender_id', $user->id)
                        ->where('recipient_id', Auth::id());
                })
                ->orderBy('created_at', 'asc')
                ->get();

            // Return the messages as a JSON response
            return response()->json($messages);
        } catch (\Exception $e) {
            // Log the error and return an appropriate error response
            Log::error('Failed to fetch messages: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to load messages',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}