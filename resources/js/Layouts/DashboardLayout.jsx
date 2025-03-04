import React from 'react';
import Menu from '@/Components/Menu';
import Navbar from '@/Components/Navbar';
import { Link } from '@inertiajs/react'; // Use Inertia's Link component

export default function DashboardLayout({ children }) {
  return (
    <div className="h-screen flex">
      {/* LEFT - Sidebar */}
      <div className="w-[14%] md:w-[8%] lg:w-[16%] xl:w-[14%] p-4">
        <Link href="/admin" className="flex items-center justify-center lg:justify-start gap-2">
          <img src="/logo.png" alt="logo" width={32} height={32} /> {/* Use <img> instead of Next.js Image */}
          <span className="hidden lg:block font-bold">TIKO SCHOOL</span>
        </Link>
        <Menu />
      </div>

      {/* RIGHT - Main Content */}
      <div className="w-[86%] md:w-[92%] lg:w-[84%] xl:w-[86%] bg-[#F7F8FA] overflow-scroll flex flex-col">
        <Navbar />
        {children} {/* This will render the page content */}
      </div>
    </div>
  );
}