import React, { useState, useEffect } from 'react';
import { Link, usePage } from '@inertiajs/react';
import Menu from '@/Components/Menu';
import Navbar from '@/Components/Navbar';
import InboxPopup from '@/Components/InboxPopup';

export default function DashboardLayout({ children }) {
    const { auth, users } = usePage().props;
    const [showInbox, setShowInbox] = useState(false);
    const [onlineUsers, setOnlineUsers] = useState([]);
    const [unreadCount, setUnreadCount] = useState(0);
    const { props } = usePage();
    const user = props.auth.user;
    useEffect(() => {
        // Fetch initial unread count
        const fetchUnreadCount = async () => {
            try {
                const response = await axios.get('/unread-count');
                setUnreadCount(response.data.unread_count);
            } catch (error) {
                console.error('Failed to fetch unread count', error);
            }
        };
        fetchUnreadCount();

        // Listen to unread count updates
        window.Echo.channel(`user.${auth.user.id}.notifications`)
            .listen('.UnreadMessageCountUpdated', (e) => {
                console.log('listening');
                console.log('Unread message count updated:', e);
                setUnreadCount(e.unread_count);
            });

        return () => {
            window.Echo.leave(`user.${auth.user.id}.notifications`);
        };
    }, [auth.user]);
    useEffect(() => {
        if (!auth.user) return;

        // Initialize Echo and listen for presence channel
        window.Echo.join(`presence-online-users`)
            .here(users => {
                setOnlineUsers(users.map(u => u.id));
            })
            .joining(user => {
                setOnlineUsers(prev => [...prev, user.id]);
            })
            .leaving(user => {
                setOnlineUsers(prev => prev.filter(id => id !== user.id));
            });

        return () => {
            window.Echo.leave(`presence-online-users`);
        };
    }, [auth.user]);
    const handleClosePopup = async () => {
        try {
            await axios.post(`/message/${auth.user.id}/read`); // Make sure this route marks messages as read
            setUnreadCount(0); // Update unread count immediately in UI
        } catch (error) {
            console.error('Failed to update unread count:', error);
        }
        setShowInbox(false);
    };
    // Enhance users with online status
    const usersWithStatus = (users || []).map(user => ({
        ...user,
        status: onlineUsers.includes(user.id) ? 'online' : 'offline'
    }));

    return (
        <div className="flex">
            {/* LEFT - Sidebar */}
            <div className="w-[14%] md:w-[8%] lg:w-[16%] xl:w-[14%] p-4">
                <Link href="/dashboard" className="flex items-center justify-center lg:justify-start gap-2">
                    <img src="/logo.png" alt="logo" width={32} height={32} />
                    <span className="hidden lg:block font-bold">TIKO SCHOOL</span>
                </Link>
                <Menu />
            </div>

            {/* RIGHT - Main Content */}
            <div className="w-[86%] md:w-[92%] lg:w-[84%] xl:w-[86%] bg-[#F7F8FA] flex flex-col">
                <Navbar auth={user} profile_image={props.auth.profile_image} />
                {children}

                {/* Floating message button */}
                {!showInbox && (
                    <button
                        onClick={() => setShowInbox(true)}
                        className="fixed bottom-6 right-6 bg-purple-600 text-white p-3 rounded-full shadow-lg hover:bg-purple-700 transition-colors"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                        {unreadCount > 0 && (
                            <span className="absolute -top-2 -right-2 bg-red-500 text-white rounded-full px-2 py-1 text-xs">
                                {unreadCount}
                            </span>
                        )}
                    </button>

                )
                }

                {/* Inbox Popup */}
                {showInbox && (
                    <InboxPopup
                        auth={auth}
                        users={usersWithStatus}
                        onClose={handleClosePopup}
                    />
                )}
            </div>
        </div>
    );
}