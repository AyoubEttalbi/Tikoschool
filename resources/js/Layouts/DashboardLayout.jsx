import React, { useEffect } from 'react';
import { Link, usePage, router } from '@inertiajs/react'; // Import Inertia router
import Menu from '@/Components/Menu';
import Navbar from '@/Components/Navbar';
import Chat from '@/Components/Chat';

export default function DashboardLayout({ children }) {
  const { props } = usePage();
  const user = props.auth.user;
  console.log("USER", props.auth);
  // Redirect to login if user is not authenticated
  useEffect(() => {
    if (!user) {
      router.visit('/login', { replace: true }); 
    }
  }, [user]);

  // Prevent rendering if user is not authenticated (to avoid flickering)
  if (!user) return null;

  return (
    <div className="flex">
      <div className=" ">
        <Chat />
      </div>
      
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
        {children} {/* This will render the page content */}
      </div>
    </div>
  );
}
