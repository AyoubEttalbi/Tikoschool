import { useEffect, useRef, useState } from 'react';
import axios from 'axios';

// Avatar Component
const Avatar = ({ src, status }) => {
    const statusColors = {
        online: "bg-green-500",
        away: "bg-yellow-500",
        offline: "bg-gray-400",
        busy: "bg-red-500"
    };
    return (
        <div className="relative">
            {src ? (
                <img
                    src={src}
                    alt="avatar"
                    className="rounded-full w-10 h-10 object-cover border border-gray-200"
                />
            ) : (
                <div className="rounded-full w-10 h-10 bg-gray-300 flex items-center justify-center border border-gray-200">
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        className="h-6 w-6 text-gray-500"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                            d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                        />
                    </svg>
                </div>
            )}
            {status && (
                <span className={`absolute bottom-0 right-0 w-2.5 h-2.5 ${statusColors[status]} rounded-full border-2 border-white`}></span>
            )}
        </div>
    );
};

// ContactItem Component
const ContactItem = ({ user, isActive, onClick, unreadCount, lastMessage, currentUserId }) => {
    const getLastMessagePreview = () => {
        if (!lastMessage) return "No messages yet";
        
        const isCurrentUserSender = lastMessage.sender_id === currentUserId;
        const prefix = isCurrentUserSender ? "You: " : "";
        return `${prefix}${lastMessage.message}`;
    };

    return (
        <div
            className={`flex items-center p-3 cursor-pointer transition-colors relative ${isActive ? 'bg-blue-50' : 'hover:bg-gray-50'}`}
            onClick={onClick}
        >
            <Avatar src={user.profile_image} status={user.status || "offline"} />
            <div className="ml-3 flex-grow overflow-hidden">
                <div className="flex justify-between items-center">
                    <p className="font-medium text-gray-800 truncate">{user.name}</p>
                    <div className="flex items-center">
                        {unreadCount > 0 && (
                            <span className="bg-red-500 text-white text-xs rounded-full px-2 py-0.5 mr-2">
                                {unreadCount}
                            </span>
                        )}
                        <p className="text-xs text-gray-500 whitespace-nowrap ml-2">
                            {lastMessage?.created_at ? new Date(lastMessage.created_at).toLocaleTimeString([], {
                                hour: '2-digit',
                                minute: '2-digit'
                            }) : ''}
                        </p>
                    </div>
                </div>
                <p className={`text-sm truncate ${unreadCount > 0 ? 'font-medium text-gray-800' : 'text-gray-500'}`}>
                    {getLastMessagePreview()}
                </p>
            </div>
        </div>
    );
};

// ChatMessage Component
const ChatMessage = ({ message = {}, isUser }) => {
    return (
        <div className={`flex mb-4 ${isUser ? 'justify-end' : 'justify-start'}`}>
            <div className={`max-w-xs p-3 rounded-lg ${isUser
                ? 'bg-blue-600 text-white rounded-br-none'
                : 'bg-gray-100 text-gray-800 rounded-bl-none'
                }`}>
                <p className="text-sm">{message.message}</p>
                <div className="text-xs mt-1 opacity-70">
                    {new Date(message.created_at).toLocaleTimeString([], {
                        hour: '2-digit',
                        minute: '2-digit'
                    })}
                </div>
            </div>
        </div>
    );
};

// Main InboxPopup Component
export default function InboxPopup({ auth, users = [], onClose }) {
    const userId = auth?.user?.id;
    const webSocketChannel = userId ? `message.${userId}` : null;
    const [selectedUser, setSelectedUser] = useState(null);
    const [currentMessages, setCurrentMessages] = useState([]);
    const [messageInput, setMessageInput] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [isTyping, setIsTyping] = useState(false);
    const [unreadMessages, setUnreadMessages] = useState({});
    const [lastMessages, setLastMessages] = useState({});
    const messagesEndRef = useRef(null);
    const selectedUserRef = useRef(selectedUser);
    const typingTimeoutRef = useRef(null);

    // Fetch initial data with proper sorting
    const fetchInitialData = async () => {
        try {
            // Fetch unread counts
            const unreadResponse = await axios.get('/unread-count');
            setUnreadMessages(unreadResponse.data.unread_count || {});
            
            // Fetch last messages for all conversations (sorted by newest first)
            const lastMessagesData = {};
            await Promise.all(users.map(async (user) => {
                try {
                    const response = await axios.get(`/message/${user.id}`, {
                        params: { 
                            limit: 1,
                            sort: 'desc' // Ensure we get the most recent message
                        }
                    });
                    if (response.data.length > 0) {
                        // Double-check sorting on client side
                        const sortedMessages = [...response.data].sort((a, b) => 
                            new Date(b.created_at) - new Date(a.created_at)
                        );
                        lastMessagesData[user.id] = sortedMessages[0];
                    }
                } catch (err) {
                    console.error(`Failed to fetch last message for user ${user.id}`, err);
                }
            }));
            setLastMessages(lastMessagesData);

           
        } catch (err) {
            console.error('Failed to fetch initial data', err);
        }
    };

    // WebSocket connection
    const connectWebSocket = () => {
        console.log("📡 Connecting to WebSocket...");

        window.Echo.private(webSocketChannel)
        .listen('.MessageSent', async (e) => {
            console.log("📩 Message received:", e);

                if (e.message.sender_id !== userId && 
                    (!selectedUserRef.current || selectedUserRef.current.id !== e.message.sender_id)) {
                    setUnreadMessages(prev => ({
                        ...prev,
                        [e.message.sender_id]: (prev[e.message.sender_id] || 0) + 1
                    }));
                }
                await getMessages();
               
            })
            .listenForWhisper('typing', (e) => {
                if (e.userId === selectedUserRef.current?.id) {
                    setIsTyping(e.isTyping);
                    if (typingTimeoutRef.current) {
                        clearTimeout(typingTimeoutRef.current);
                    }
                    if (e.isTyping) {
                        typingTimeoutRef.current = setTimeout(() => {
                            setIsTyping(false);
                        }, 2000);
                    }
                }
            });
    };

    // Get messages with proper sorting
    const getMessages = async () => {
        if (!selectedUserRef.current?.id) return;
        
        try {
            setLoading(true);
            const { data } = await axios.get(`/message/${selectedUserRef.current.id}`);
            setCurrentMessages(data);
            
            // If needed, sort again on client side
            const sortedMessages = [...data].sort((a, b) => 
                new Date(b.created_at) - new Date(a.created_at)
            );
            
            
            if (sortedMessages.length > 0) {
                setLastMessages(prev => ({
                    ...prev,
                    [selectedUserRef.current.id]: sortedMessages[0] // Use the first (newest) message
                }));
            }
        } catch (err) {
            setError('Failed to load messages');
            console.error('Error:', err);
        } finally {
            setLoading(false);
            scrollToBottom();
        }
    };

    // Send message
    const sendMessage = async () => {
        if (!messageInput.trim() || !selectedUserRef.current?.id) return;
        
        try {
            setLoading(true);
            setError(null);
            const socketId = window.Echo.socketId();
            
            const response = await axios.post(`/message/${selectedUserRef.current.id}`, {
                message: messageInput
            }, {
                headers: {
                    'X-Socket-ID': socketId
                }
            });

            setMessageInput('');
            const newMessage = response.data.message;
            
            setCurrentMessages(prev => [...prev, newMessage]);
            setLastMessages(prev => ({
                ...prev,
                [selectedUserRef.current.id]: newMessage
            }));
            
            scrollToBottom();
        } catch (err) {
            setError(err.response?.data?.message || 'Failed to send message');
            console.error('Error:', err);
        } finally {
            setLoading(false);
        }
    };

    // Mark messages as read
    const markMessagesAsRead = async () => {
        if (!selectedUserRef.current) return;
        
        try {
            await axios.post(`/message/${selectedUserRef.current.id}/read`);
            setUnreadMessages(prev => {
                const updated = { ...prev };
                delete updated[selectedUserRef.current.id];
                return updated;
            });
        } catch (error) {
            console.error('Failed to mark messages as read', error);
        }
    };

    // Scroll to bottom
    const scrollToBottom = () => {
        setTimeout(() => {
            messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
        }, 100);
    };

    // Sort users with unread messages first, then by last message time
    const sortedUsers = [...users].sort((a, b) => {
        const aUnread = unreadMessages[a.id] || 0;
        const bUnread = unreadMessages[b.id] || 0;
        
        if (aUnread !== bUnread) {
            return bUnread - aUnread;
        }
        
        const aLastMessageTime = lastMessages[a.id]?.created_at ? 
            new Date(lastMessages[a.id].created_at).getTime() : 0;
        const bLastMessageTime = lastMessages[b.id]?.created_at ? 
            new Date(lastMessages[b.id].created_at).getTime() : 0;
            
        return bLastMessageTime - aLastMessageTime;
    });

    // Filter users based on search query
    const filteredUsers = sortedUsers.filter(user =>
        user.name.toLowerCase().includes(searchQuery.toLowerCase())
    );

    // Handle typing
    const handleTyping = () => {
        if (!selectedUserRef.current?.id) return;
        const channel = window.Echo.private(webSocketChannel);
        channel.whisper('typing', {
            userId: auth.user.id,
            isTyping: true
        });
        if (typingTimeoutRef.current) {
            clearTimeout(typingTimeoutRef.current);
        }
        typingTimeoutRef.current = setTimeout(() => {
            channel.whisper('typing', {
                userId: auth.user.id,
                isTyping: false
            });
            setIsTyping(false);
        }, 2000);
    };

    // Effect for initial data loading
    useEffect(() => {
        if (userId) {
            fetchInitialData();
            connectWebSocket();
        }

        return () => {
            if (userId) {
                window.Echo.leave(webSocketChannel);
            }
        };
    }, [userId]);

    // Effect for handling user selection
    useEffect(() => {
        selectedUserRef.current = selectedUser;
        if (selectedUser) {
            getMessages();
            markMessagesAsRead();
        }
    }, [selectedUser]);

    // Effect for scrolling when messages change
    useEffect(() => {
        scrollToBottom();
    }, [currentMessages]);

    if (!userId) {
        return null;
    }

    return (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
            <div className="bg-white rounded-xl shadow-xl w-full max-w-6xl max-h-[90vh] flex flex-col">
                {/* Header */}
                <div className="p-4 border-b border-gray-200 flex justify-between items-center">
                    <h2 className="text-lg font-semibold text-gray-800">Messages</h2>
                    <button
                        onClick={onClose}
                        className="p-2 rounded-full hover:bg-gray-100 text-gray-600 transition duration-150"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div className="flex flex-1 overflow-hidden">
                    {/* Contact List */}
                    <div className="w-1/3 border-r border-gray-200 flex flex-col">
                        {/* Search */}
                        <div className="p-4 border-b border-gray-200">
                            <div className="relative">
                                <input
                                    type="text"
                                    placeholder="Search contacts..."
                                    className="w-full px-4 py-2 bg-gray-100 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                />
                                <button className="absolute right-3 top-2 text-gray-500">
                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                        <path fillRule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clipRule="evenodd" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                        {/* Contact list */}
                        <div className="overflow-y-auto flex-grow">
                            <div className="divide-y divide-gray-200">
                                {filteredUsers.map(user => (
                                    <ContactItem
                                        key={user.id}
                                        user={user}
                                        isActive={selectedUser?.id === user.id}
                                        onClick={() => setSelectedUser(user)}
                                        unreadCount={unreadMessages[user.id] || 0}
                                        lastMessage={lastMessages[user.id]}
                                        currentUserId={userId}
                                    />
                                ))}
                            </div>
                        </div>
                    </div>
                    {/* Chat Area */}
                    <div className="w-2/3 flex flex-col">
                        {selectedUser ? (
                            <>
                                {/* Chat Header */}
                                <div className="px-4 py-3 border-b border-gray-200 flex items-center bg-white">
                                    <Avatar
                                        src={selectedUser.profile_image}
                                        status={selectedUser.status}
                                    />
                                    <div className="ml-3">
                                        <p className="font-medium text-gray-800">
                                            {selectedUser.name}
                                        </p>
                                        <p className="text-xs text-gray-500">
                                            {isTyping ? (
                                                <span className="text-blue-500">Typing...</span>
                                            ) : selectedUser.status === "online" ? (
                                                <span className="text-green-500">Online</span>
                                            ) : (
                                                <span className="text-gray-500">Offline</span>
                                            )}
                                        </p>
                                    </div>
                                </div>
                                {/* Messages */}
                                <div className="flex-grow p-4 overflow-y-auto bg-gray-50">
                                    {[...currentMessages].map((message, index) => (
                                        <ChatMessage
                                            key={message.id || `msg-${index}`}
                                            message={message}
                                            isUser={message.sender_id === userId}
                                        />
                                    ))}
                                    <div ref={messagesEndRef} />
                                </div>
                                {/* Message Input */}
                                <div className="border-t border-gray-200 p-4 bg-white">
                                    <form
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            sendMessage();
                                        }}
                                        className="flex items-center"
                                    >
                                        <input
                                            type="text"
                                            placeholder="Type your message..."
                                            className="flex-grow mx-3 px-4 py-2 bg-gray-100 rounded-full focus:outline-none focus:ring-2 focus:ring-blue-400"
                                            value={messageInput}
                                            onChange={(e) => {
                                                setMessageInput(e.target.value);
                                                handleTyping();
                                            }}
                                            disabled={loading}
                                        />
                                        <button
                                            type="submit"
                                            disabled={loading || !messageInput.trim()}
                                            className="p-2 bg-blue-600 rounded-full text-white hover:bg-blue-700 transition duration-150 disabled:opacity-50"
                                        >
                                            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                                <path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z" />
                                            </svg>
                                        </button>
                                    </form>
                                    {error && (
                                        <div className="text-red-500 text-sm mt-2 text-center">
                                            {error}
                                        </div>
                                    )}
                                </div>
                            </>
                        ) : (
                            <div className="flex-grow flex items-center justify-center bg-gray-50">
                                <div className="text-center p-6">
                                    <svg xmlns="http://www.w3.org/2000/svg" className="h-12 w-12 mx-auto text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                    </svg>
                                    <h3 className="mt-2 text-lg font-medium text-gray-900">No conversation selected</h3>
                                    <p className="mt-1 text-gray-500">Select a contact to start chatting</p>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}