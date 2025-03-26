import React, { useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import Menu from '@/Components/Menu';
import Navbar from '@/Components/Navbar';
import InboxPopup from '@/Components/InboxPopup';

export default function DashboardLayout({ children }) {
  const { props } = usePage();
    const { auth, users } = usePage().props;
    const [showInbox, setShowInbox] = useState(false);
    const user = props.auth.user;
    
  console.log("USER", props.auth);
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
            <Navbar auth={user} profile_image={props.auth.profile_image}/>
                {children}

                {/* Floating message button */}
                <button 
                    onClick={() => setShowInbox(true)}
                    className="fixed bottom-6 right-6 bg-purple-600 text-white p-3 rounded-full shadow-lg hover:bg-purple-700 transition-colors"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                    </svg>
                </button>

                {/* Inbox Popup */}
                {showInbox && (
                    <InboxPopup 
                        auth={auth} 
                        users={users || []} 
                        onClose={() => setShowInbox(false)}
                    />
                )}
            </div>
        </div>
    );
}