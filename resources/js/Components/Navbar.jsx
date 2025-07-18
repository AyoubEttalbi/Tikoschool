import { router, usePage } from "@inertiajs/react";
import { useState, useEffect } from "react";
import Announcements from "../Pages/Menu/Announcements/Announcements";
import { CalendarIcon, BellIcon, ClockIcon } from "@heroicons/react/24/outline";
import axios from "axios";

const Navbar = ({ auth, profile_image }) => {
    // Check if the admin is viewing as another user
    const isViewingAs = usePage().props.auth.isViewingAs;

    const [switchBackError, setSwitchBackError] = useState(null);
    const [isLoading, setIsLoading] = useState(false);
    const [showAnnouncements, setShowAnnouncements] = useState(false);
    const [upcomingAnnouncement, setUpcomingAnnouncement] = useState(null);

    const unreadCount = usePage().props.unreadCount || 0;

    // Get announcements and user role from props
    const userRole = usePage().props.auth.user.role;
    
    // Fetch upcoming announcements on mount
    useEffect(() => {
        axios
            .get("/api/upcoming-announcements")
            .then((response) => {
                const now = new Date();
                const announcements = response.data.announcements || [];
                // Find the soonest upcoming announcement visible to this user
                const soonest = announcements.find(a => {
                    if (!a.date_start) return false;
                    const start = new Date(a.date_start);
                    return start > now && (a.visibility === "all" || a.visibility === userRole);
                });
                setUpcomingAnnouncement(soonest || null);
            });
    }, [userRole]);

    // Format current date
    const formatDate = (date) => date.toLocaleDateString("fr-FR", { 
        weekday: "long", 
        year: "numeric", 
        month: "long", 
        day: "numeric" 
    });
    const todayStr = formatDate(new Date());

    // Format upcoming announcement date
    const formatUpcomingDate = (dateString) => {
        const date = new Date(dateString);
        const now = new Date();
        const diffTime = date - now;
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        
        if (diffDays === 0) return "Aujourd'hui";
        if (diffDays === 1) return "Demain";
        if (diffDays <= 7) return `Dans ${diffDays} jours`;
        return date.toLocaleDateString("fr-FR", { month: "short", day: "numeric" });
    };

    // Handle switching back to the admin account
    const handleSwitchBack = () => {
        setSwitchBackError(null);
        router.post('/admin/switch-back', {}, {
            replace: true,
            onSuccess: () => {
                window.location.href = '/dashboard';
            },
            onError: (errors) => {
                setSwitchBackError("Failed to switch back. Please try again or check your session.");
                console.error("Error switching back:", errors);
            },
        });
    };

    const handleOpenAnnouncements = () => {
        setShowAnnouncements(true);
        // Mark all visible announcements as read
        router.post('/announcements/mark-all-read', {}, {
            preserveScroll: true,
            onSuccess: () => {
                // Optionally, you can refresh the unreadCount here if needed
            },
        });
    };

    return (
        <div className="flex items-center justify-between p-4">
            {/* IMPROVED DATE & ANNOUNCEMENT SECTION */}
            <div className="flex items-center gap-3 w-full">
                {/* Current Date */}
                <div className="flex items-center gap-3 bg-white rounded-xl px-4 py-2.5 shadow-sm border border-gray-100 hover:shadow-md transition-shadow duration-200">
                    <div className="flex items-center justify-center w-8 h-8 bg-blue-50 rounded-lg">
                        <CalendarIcon className="h-4 w-4 text-blue-600" />
                    </div>
                    <div className="flex flex-col">
                        <span className="text-sm font-semibold text-gray-900 leading-tight">
                            {todayStr}
                        </span>
                        <span className="text-xs text-gray-500">
                            Aujourd'hui
                        </span>
                    </div>
                </div>

                {/* Upcoming Announcement */}
                {upcomingAnnouncement && (
                    <div className="flex items-center gap-3 bg-gradient-to-r from-amber-50 to-orange-50 rounded-xl px-4 py-2.5 shadow-sm border border-amber-200 hover:shadow-md transition-all duration-200 cursor-pointer group max-w-xs"
                         onClick={handleOpenAnnouncements}
                         title={upcomingAnnouncement.title}>
                        <div className="flex items-center justify-center w-8 h-8 bg-amber-100 rounded-lg group-hover:bg-amber-200 transition-colors duration-200">
                            <BellIcon className="h-4 w-4 text-amber-600" />
                        </div>
                        <div className="flex flex-col flex-1 min-w-0">
                            <span className="text-sm font-semibold text-amber-900 truncate leading-tight">
                                {upcomingAnnouncement.title}
                            </span>
                            <div className="flex items-center gap-1.5 mt-0.5">
                                <ClockIcon className="h-3 w-3 text-amber-600" />
                                <span className="text-xs text-amber-700 font-medium">
                                    {formatUpcomingDate(upcomingAnnouncement.date_start)}
                                </span>
                            </div>
                        </div>
                        <div className="flex items-center justify-center w-6 h-6 bg-amber-200 rounded-full flex-shrink-0">
                            <div className="w-2 h-2 bg-amber-500 rounded-full animate-pulse"></div>
                        </div>
                    </div>
                )}
            </div>

            {/* ICONS AND USER */}
            <div className="flex items-center gap-6 justify-end w-full">
                {/* Announcement Icon */}
                <div className="relative">
                    <div className="bg-white rounded-full w-10 h-10 flex items-center justify-center cursor-pointer shadow-sm border border-gray-100 hover:shadow-md transition-shadow duration-200" 
                         onClick={handleOpenAnnouncements}>
                        <img
                            src="/announcement.png"
                            alt="Announcements"
                            width={20}
                            height={20}
                        />
                        {unreadCount > 0 && (
                            <div className="absolute -top-1 -right-1 w-5 h-5 flex items-center justify-center bg-red-500 text-white rounded-full text-xs font-medium animate-pulse">
                                {unreadCount > 9 ? '9+' : unreadCount}
                            </div>
                        )}
                    </div>
                </div>

                {/* Announcements Popup Modal */}
                {showAnnouncements && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-40 backdrop-blur-sm" 
                         onClick={() => setShowAnnouncements(false)}>
                        <div className="bg-white rounded-xl shadow-2xl w-full max-w-md md:max-w-lg p-0 md:p-4 relative overflow-y-auto max-h-[90vh] mx-4" 
                             onClick={e => e.stopPropagation()}>
                            <button className="absolute top-2 right-2 text-gray-400 hover:text-gray-700 text-2xl font-bold z-10" 
                                    onClick={() => setShowAnnouncements(false)}>
                                &times;
                            </button>
                            <Announcements
                                announcements={usePage().props.announcements || []}
                                userRole={usePage().props.auth.user.role}
                                limit={5}
                                schoolId={usePage().props.selectedSchoolId}
                            />
                        </div>
                    </div>
                )}

                {/* User Info */}
                <div className="flex flex-col">
                    <span className="text-xs leading-3 font-medium">
                        {auth.name}
                    </span>
                    <span className="text-[10px] text-gray-500 text-right">
                        {auth.role}
                    </span>
                </div>

                {/* User Avatar */}
                <img
                    src={profile_image ? profile_image : "/avatar.png"}
                    alt=""
                    width={36}
                    height={36}
                    className="rounded-full"
                />

                {/* Switch Back Button (Visible only when admin is viewing as another user) */}
                {isViewingAs && (
                    <div className="flex flex-col items-end gap-2">
                        <button
                            onClick={handleSwitchBack}
                            disabled={isLoading}
                            className={`p-2 text-white rounded-full transition duration-200 ${
                                isLoading 
                                    ? 'bg-gray-400 cursor-not-allowed' 
                                    : 'bg-blue-500 hover:bg-blue-600'
                            }`}
                        >
                            {isLoading ? 'Switching...' : 'Switch Back to Admin'}
                        </button>
                        
                        {switchBackError && (
                            <div className="text-red-500 text-xs mt-1 max-w-xs">
                                {switchBackError}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
};

export default Navbar;