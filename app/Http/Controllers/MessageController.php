<?php

namespace App\Http\Controllers;

use App\Events\MessageSent;
use App\Events\MessagesRead;
use App\Events\UnreadMessageCountUpdated;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageController extends Controller
{
    /**
     * Store a newly created message in storage and broadcast it.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request, User $user)
    {
        // Validate the incoming request
        $request->validate([
            'message' => 'required|string|max:500',
        ]);

        try {

            // Create the message in the database
            $message = Message::create([
                'sender_id' => auth()->id(),
                'recipient_id' => $user->id,
                'message' => $request->message,
            ]);
            $unreadCount = Message::where('recipient_id', $user->id)
                ->where('is_read', false)
                ->count();

            // Broadcast unread count
            broadcast(new UnreadMessageCountUpdated($user->id, $unreadCount));
            // Broadcast the event to both sender and recipient
            broadcast(new MessageSent($message, $user->id))->toOthers();

            // Return the created message as a JSON response
            return response()->json([
                'message' => $message->load('sender', 'recipient'),
                'status' => 'success',
            ], 201);
        } catch (\Exception $e) {
            // Log the error and return an appropriate error response
            Log::error('Message send failed: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to send message',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Retrieve all messages between the authenticated user and the specified recipient.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(User $user)
    {
        try {
            // The most recent page only, returned oldest-first for the UI.
            //
            // This used to be ->orderBy('created_at','asc')->get() — the ENTIRE
            // conversation, unbounded — and InboxPopup renders every row with no
            // virtualization. A long-running staff conversation held one of only five
            // php-fpm workers while it serialized.
            //
            // ->with(['sender','recipient']) is also dropped: a conversation has exactly
            // two participants and the caller already knows both, so eager-loading two
            // relations per row paid for data the client does not use.
            $limit = 50;

            $messages = Message::query()
                ->where(function ($query) use ($user) {
                    $query->where(function ($q) use ($user) {
                        $q->where('sender_id', Auth::id())
                            ->where('recipient_id', $user->id);
                    })->orWhere(function ($q) use ($user) {
                        $q->where('sender_id', $user->id)
                            ->where('recipient_id', Auth::id());
                    });
                })
                // `before` is a message id: pass the oldest id you already hold to page
                // backwards through the history.
                ->when(
                    request()->filled('before'),
                    fn ($q) => $q->where('id', '<', (int) request()->input('before'))
                )
                ->orderBy('id', 'desc')
                ->limit($limit)
                ->get()
                ->reverse()
                ->values();

            // Return the messages as a JSON response
            return response()->json($messages);
        } catch (\Exception $e) {
            // Log the error and return an appropriate error response
            Log::error('Failed to fetch messages: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to load messages',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    public function markAsRead(User $user)
    {
        try {
            // Validate authentication
            if (! Auth::check()) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }

            $currentUser = Auth::user();

            DB::beginTransaction();

            // Get unread messages (including newly received ones in active chat)
            $messagesToMark = Message::where(function ($query) use ($user, $currentUser) {
                $query->where('sender_id', $user->id)
                    ->where('recipient_id', $currentUser->id);
            })
                ->where('is_read', false)
                ->get();

            if ($messagesToMark->isEmpty()) {
                DB::commit();

                return response()->json([
                    'status' => 'info',
                    'message' => 'No unread messages to mark',
                ]);
            }

            // Get message IDs before update for broadcasting
            $messageIds = $messagesToMark->pluck('id')->toArray();

            // Perform bulk update with timestamp
            $updatedCount = Message::whereIn('id', $messageIds)
                ->update([
                    'is_read' => true,
                    'updated_at' => now(), // Explicitly set update time
                ]);

            // Get updated unread count (optimized query)
            $unreadCount = Message::where('recipient_id', $currentUser->id)
                ->where('is_read', false)
                ->count();

            DB::commit();

            // Broadcast events with additional context
            broadcast(new UnreadMessageCountUpdated($currentUser->id, $unreadCount, [
                'sender_id' => $user->id,
                'marked_read_count' => $updatedCount,
            ]))->toOthers();

            broadcast(new MessagesRead(
                $currentUser->id,
                $user->id,
                $messageIds,
                now()->toISOString()  // Include exact read timestamp
            ))->toOthers();

            return response()->json([
                'status' => 'success',
                'unread_count' => $unreadCount,
                'updated_messages_count' => $updatedCount,
                'message_ids' => $messageIds,
                'read_at' => now()->toISOString(),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to mark messages as read: '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'error' => 'Failed to mark messages as read',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get unread message count
     */
    public function unreadCount()
    {
        $userId = auth()->id();

        // ONE grouped aggregate.
        //
        // This used to load every user in the installation and run a separate COUNT query per
        // user — so N+1 queries where N is the entire staff directory. Every connected client
        // polls this endpoint, which made it the single hottest query path in the app.
        // Backed by the messages(recipient_id, is_read, sender_id) index.
        $counts = Message::query()
            ->where('recipient_id', $userId)
            ->where('is_read', false)
            ->selectRaw('sender_id, COUNT(*) as total')
            ->groupBy('sender_id')
            ->pluck('total', 'sender_id');

        // The frontend reduces over the values, so senders with nothing unread can be omitted.
        return response()->json([
            'unread_count' => $counts->map(fn ($n) => (int) $n),
        ]);
    }

    public function getLastMessages(Request $request)
    {
        $userId = auth()->id();
        $contactIds = $request->input('contact_ids', []);

        // Get the most recent message date for each conversation
        $latestMessages = DB::table('messages')
            ->selectRaw("
            CASE 
                WHEN sender_id = $userId THEN recipient_id
                ELSE sender_id
            END as contact_id,
            MAX(created_at) as latest_date
        ")
            ->where(function ($query) use ($userId, $contactIds) {
                $query->where('sender_id', $userId)
                    ->whereIn('recipient_id', $contactIds);
            })
            ->orWhere(function ($query) use ($userId, $contactIds) {
                $query->where('recipient_id', $userId)
                    ->whereIn('sender_id', $contactIds);
            })
            ->groupBy('contact_id');

        // Join with the messages table to fetch full details
        $lastMessages = DB::table('messages')
            ->joinSub($latestMessages, 'latest', function ($join) use ($userId) {
                $join->on('messages.created_at', '=', 'latest.latest_date')
                    ->whereRaw("
                    CASE 
                        WHEN messages.sender_id = $userId THEN messages.recipient_id
                        ELSE messages.sender_id
                    END = latest.contact_id
                 ");
            })
            ->select('messages.*')
            ->get()
            ->keyBy(function ($message) use ($userId) {
                return $message->sender_id == $userId
                    ? $message->recipient_id
                    : $message->sender_id;
            });

        return response()->json([
            'lastMessages' => $lastMessages,
        ]);
    }
}
