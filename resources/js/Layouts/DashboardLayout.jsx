import React, { useState, useEffect, useMemo } from "react";
import { Link, usePage } from "@inertiajs/react";
import Menu from "@/Components/Menu";
import Navbar from "@/Components/Navbar";
import PaymentNoticeDialog from "@/Components/PaymentNoticeDialog";
import axios from "axios";
import { getEcho, currentEcho } from "@/echo";

// Lazily loaded: the chat client (and its emoji picker) is a large chunk that most users
// never open, but it used to be pulled into the eager entry bundle on every page.
const InboxPopup = React.lazy(() => import("@/Components/InboxPopup"));

export default function DashboardLayout({ children }) {
    const page = usePage();
    const { auth, chatContacts } = page.props;
    const user = page.props.auth.user;
    const [showInbox, setShowInbox] = useState(false);
    const [onlineUsers, setOnlineUsers] = useState([]);
    const [unreadCount, setUnreadCount] = useState(() => {
        // Initialize from localStorage if available
        const saved = localStorage.getItem("totalUnreadCount");
        return saved ? parseInt(saved, 10) : 0;
    });
    // Absence notices awaiting approval — the sidebar badge on "Présences". Seeded from
    // the page-load prop, then refreshed by the 60s reconciliation poll below.
    const [pendingNotices, setPendingNotices] = useState(
        () => page.props.pendingNoticesCount ?? 0,
    );

    // Function to update unread count and save to localStorage
    const updateUnreadCount = (count) => {
        setUnreadCount(count);
        localStorage.setItem("totalUnreadCount", count.toString());
    };

    useEffect(() => {
        // Fetch initial unread count
        const fetchUnreadCount = async () => {
            try {
                const response = await axios.get("/unread-count");
                const totalCount = Object.values(
                    response.data.unread_count,
                ).reduce((sum, count) => sum + count, 0);
                updateUnreadCount(totalCount);
                if (typeof response.data.pending_notices === "number") {
                    setPendingNotices(response.data.pending_notices);
                }
            } catch (error) {
                console.error("Failed to fetch unread count", error);
                // If fetch fails, try to use cached count
                const saved = localStorage.getItem("totalUnreadCount");
                if (saved) {
                    setUnreadCount(parseInt(saved, 10));
                }
            }
        };

        // Reconciliation poll only — Echo below pushes the count in real time, so this is
        // just a safety net for a dropped websocket. It was every 10s (despite the comment
        // claiming 30s) and InboxPopup ran a SECOND 10s poll against the same endpoint;
        // each request re-ran the whole Inertia share() including two N+1 loops.
        // Now: 60s, and paused while the tab is hidden.
        const syncInterval = setInterval(() => {
            if (document.visibilityState === "visible") {
                fetchUnreadCount();
            }
        }, 60000);

        // PRIVATE channel — must match UnreadMessageCountUpdated::broadcastOn().
        // Using Echo.channel() here made this a public subscription, which bypassed the
        // Broadcast::channel() guard in routes/channels.php entirely.
        //
        // Echo is loaded on demand (see resources/js/echo.js), so this is async. `cancelled`
        // guards against the effect being torn down before the client finishes connecting.
        const channelName = `user.${auth.user.id}.notifications`;
        let cancelled = false;
        let channel = null;

        getEcho().then((echo) => {
            if (cancelled) return;
            channel = echo.private(channelName);
            channel.listen(".UnreadMessageCountUpdated", (e) => {
                const totalCount = Object.values(e.unread_count).reduce(
                    (sum, count) => sum + count,
                    0,
                );
                updateUnreadCount(totalCount);
            });
        });

        // Initial fetch
        fetchUnreadCount();

        return () => {
            cancelled = true;
            clearInterval(syncInterval);
            channel?.stopListening(".UnreadMessageCountUpdated");
            currentEcho()?.leave(channelName);
        };
    }, [auth.user.id]);

    useEffect(() => {
        if (!auth.user) return;

        let cancelled = false;

        getEcho().then((echo) => {
            if (cancelled) return;
            echo.join(`presence-online-users`)
                .here((users) => {
                    setOnlineUsers(users.map((u) => u.id));
                })
                .joining((user) => {
                    setOnlineUsers((prev) => [...prev, user.id]);
                })
                .leaving((user) => {
                    setOnlineUsers((prev) => prev.filter((id) => id !== user.id));
                });
        });

        return () => {
            cancelled = true;
            currentEcho()?.leave(`presence-online-users`);
        };
    }, [auth.user]);

    const handleClosePopup = async () => {
        try {
            await axios.post(`/message/${auth.user.id}/read`);
            updateUnreadCount(0); // Update unread count and save to localStorage
        } catch (error) {
            console.error("Failed to update unread count:", error);
        }
        setShowInbox(false);
    };

    // Enhance contacts with online status.
    //
    // This read `users.data`, but the shared prop was a plain array — so `.data` was
    // undefined and this evaluated to [] on every page EXCEPT /users (where the
    // UserController paginator happened to shadow the shared prop under the same key).
    // The chat contact list was therefore empty almost everywhere. The prop is now
    // named `chatContacts` so a page prop can never shadow it again.
    const usersWithStatus = useMemo(
        () =>
            (Array.isArray(chatContacts) ? chatContacts : []).map((user) => ({
                ...user,
                status: onlineUsers.includes(user.id) ? "online" : "offline",
            })),
        [chatContacts, onlineUsers],
    );

    return (
        <div className="flex">
            {/* LEFT - Sidebar */}
            <div className="w-[14%] md:w-[8%] lg:w-[16%] xl:w-[14%] p-4">
                <Link
                    href="/dashboard"
                    className="flex items-center justify-center lg:justify-start gap-2"
                >
                    <img src="/logo.png" alt="logo" width={32} height={32} />
                    <span className="hidden lg:block font-bold">
                        TIKO SCHOOL
                    </span>
                </Link>
                <Menu pendingNotices={pendingNotices} />
            </div>

            {/* RIGHT - Main Content */}
            <div className="w-[86%] md:w-[92%] lg:w-[84%] xl:w-[86%] bg-[#F7F8FA] flex flex-col">
                <Navbar auth={user} profile_image={page.props.auth.profile_image} />

                {/* Mounted once here rather than per page: every controller that touches
                    teacher money flashes through the same channel, so no page has to opt in. */}
                <PaymentNoticeDialog />

                {children}

                {/* Floating message button */}
                {!showInbox && (
                    <button
                        onClick={() => setShowInbox(true)}
                        className="fixed bottom-6 right-6 bg-purple-600 text-white p-3 rounded-full shadow-lg hover:bg-purple-700 transition-colors"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            className="h-6 w-6"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                strokeWidth={2}
                                d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
                            />
                        </svg>
                        {unreadCount > 0 && (
                            <span className="absolute -top-2 -right-2 bg-red-500 text-white rounded-full px-2 py-1 text-xs">
                                {unreadCount}
                            </span>
                        )}
                    </button>
                )}

                {/* Inbox Popup — lazy chunk, so it needs a Suspense boundary */}
                {showInbox && (
                    <React.Suspense fallback={null}>
                        <InboxPopup
                            auth={auth}
                            users={usersWithStatus}
                            onClose={handleClosePopup}
                        />
                    </React.Suspense>
                )}
            </div>
        </div>
    );
}
