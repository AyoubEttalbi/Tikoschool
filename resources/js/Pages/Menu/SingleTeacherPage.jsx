import Announcements from "@/Pages/Menu/Announcements/Announcements";
import BigCalendar from "@/Components/BigCalender";
import FormModal from "@/Components/FormModal";
import Performance from "@/Components/Performance";
import TeacherProfile from "@/Components/TeacherProfile";
import DashboardLayout from "@/Layouts/DashboardLayout";

import { Link, usePage } from "@inertiajs/react";

const SingleTeacherPage = ({ teacher = {}, announcements=[], classes, subjects, schools, invoices, selectedSchool, teacherSchools = [] }) => {
  const role = usePage().props.auth.user.role;
  const isAdmin = role === 'admin';
  const isViewingAs = usePage().props.auth.isViewingAs;

  return (
    <div className="flex-1 p-4 flex flex-col gap-4 xl:flex-row">
      
      
      {/* LEFT */}
      <div className="w-full xl:w-2/3">
      {/* School Selection Banner */}
      {selectedSchool && (
        <div className="w-full mb-4 p-3 bg-blue-50 border border-blue-200 rounded-md flex items-center justify-between">
          <div className="flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" className="h-5 w-5 text-blue-500" viewBox="0 0 20 20" fill="currentColor">
              <path d="M10.394 2.08a1 1 0 00-.788 0l-7 3a1 1 0 000 1.84L5.25 8.051a.999.999 0 01.356-.257l4-1.714a1 1 0 11.788 1.838l-2.727 1.666 1.94.831a1 1 0 00.787 0l7-3a1 1 0 000-1.838l-7-3zM3.31 9.397L5 10.12v4.102a8.969 8.969 0 00-1.05-.174 1 1 0 01-.89-.89 11.115 11.115 0 01.25-3.762zM9.3 16.573A9.026 9.026 0 007 14.935v-3.957l1.818.78a3 3 0 002.364 0l5.508-2.361a11.026 11.026 0 01.25 3.762 1 1 0 01-.89.89 8.968 8.968 0 00-5.35 2.524 1 1 0 01-1.4 0zM6 18a1 1 0 001-1v-2.065a8.935 8.935 0 00-2-.712V17a1 1 0 001 1z" />
            </svg>
            <span className="font-medium">Current School: {selectedSchool.name}</span>
          </div>
          <Link href="/select-profile" className="text-sm px-3 py-1 bg-blue-500 text-white rounded-md hover:bg-blue-600 transition-colors">
            Change School
          </Link>
        </div>
      )}
        {/* TOP */}
        <div className="flex flex-col lg:flex-row gap-4">
          {/* USER INFO CARD */}
          <div className="bg-lamaSky py-6 px-4 rounded-md flex-1 flex gap-4">
            <div className="w-1/3">
              <img
                src={teacher.profile_image ? teacher.profile_image : "https://images.pexels.com/photos/2888150/pexels-photo-2888150.jpeg?auto=compress&cs=tinysrgb&w=1200"}
                alt={teacher.last_name}
                width={144}
                height={144}
                className="w-36 h-36 rounded-full object-cover"
              />
            </div>
            <div className="w-2/3 flex flex-col justify-between gap-4">
              <div className="flex items-center gap-4">
                <h1 className="text-xl font-semibold">{teacher.first_name} {teacher.last_name}</h1>
                <span className={`px-2 py-1 rounded-full text-xs ${teacher.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>
                  {teacher.status}
                </span>
              </div>
              <p className="text-sm text-gray-500">
                {teacher.bio || "No bio available."}
              </p>
              <div className="flex items-center justify-between gap-2 flex-wrap text-xs font-medium">
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/school.png" alt="" width={14} height={14} />
                  <span>{teacher.schools && teacher.schools.length > 0 
                    ? teacher.schools.map(school => school.name).join(", ") 
                    : "No schools assigned"}
                  </span>
                </div>
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/date.png" alt="" width={14} height={14} />
                  <span>
                    {teacher.created_at ?
                      new Intl.DateTimeFormat('en-GB', {
                        month: 'long',
                        year: 'numeric'
                      }).format(new Date(teacher.created_at)) :
                      "N/A"
                    }
                  </span>
                </div>
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/mail.png" alt="" width={14} height={14} />
                  <span>{teacher.email}</span>
                </div>
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/phone.png" alt="" width={14} height={14} />
                  <span>{teacher.phone_number}</span>
                </div>
              </div>
            </div>
            {role === "admin" && (
              <FormModal
                table="teacher"
                type="update"
                data={teacher}
                schools={schools}
                groups={classes}
                subjects={subjects}
                icon={'updateIcon2'}
              />
            )}
          </div>

          {/* SMALL CARDS */}
          <div className="flex-1 flex gap-4 justify-between flex-wrap">
            <InfoCard icon="/singleAttendance.png" label="Number of Students" value={teacher.totalStudents || 0} />
            <InfoCard 
              icon="/singleBranch.png" 
              label="Subjects" 
              value={teacher.subjects ? teacher.subjects.length : 0} 
            />
            <InfoCard 
              icon="/singleClass.png" 
              label="Classes" 
              value={teacher.classes ? teacher.classes.length : 0} 
            />
            <InfoCard 
              icon="/singleLesson.png" 
              label="Schools" 
              value={teacher.schools ? teacher.schools.length : 0} 
            />
          </div>
        </div>
        <div className="mt-4">
          <TeacherProfile 
            invoices={invoices && invoices.data ? invoices.data : []} 
            paginate={invoices && invoices.links ? invoices.links : []} 
          />
        </div>
        {/* BOTTOM */}
        <div className="mt-4 bg-white rounded-md p-4 h-[800px]">
          <h1>Teacher&apos;s Schedule</h1>
          <BigCalendar />
        </div>
      </div>

      {/* RIGHT */}
      <div className="w-full xl:w-1/3 flex flex-col gap-4">
        <div className="bg-white p-4 rounded-md">
          <h1 className="text-xl font-semibold">Shortcuts</h1>
          <div className="mt-4 flex gap-4 flex-wrap text-xs text-gray-500">
            <ShortcutLink 
              label="Teacher's Classes" 
              href={`/classes?teacher=${teacher.id}`} 
              bgClass="bg-lamaSkyLight" 
            />
            <ShortcutLink 
              label="Take Attendance" 
              href={`/attendances?teacher_id=${teacher.id}`} 
              bgClass="bg-lamaPurpleLight" 
            />
            <ShortcutLink 
              label="Enter Grades" 
              href={`/results?teacher_id=${teacher.id}`} 
              bgClass="bg-lamaYellowLight" 
            />
            <ShortcutLink 
              label="View Students" 
              href="/students" 
              bgClass="bg-pink-50" 
            />
          </div>
        </div>
        <Performance />
        <Announcements announcements={announcements} userRole={role} />
      </div>
    </div>
  );
};

const InfoCard = ({ icon, label, value }) => (
  <div className="bg-white p-4 rounded-md flex gap-4 w-full md:w-[48%] xl:w-[45%] 2xl:w-[48%]">
    <img src={icon} alt="" width={24} height={24} className="w-6 h-6" />
    <div>
      <h1 className="text-xl font-semibold">{value}</h1>
      <span className="text-sm text-gray-400">{label}</span>
    </div>
  </div>
);

const ShortcutLink = ({ label, href, bgClass }) => (
  <Link className={`p-3 rounded-md ${bgClass}`} href={href}>
    {label}
  </Link>
);

SingleTeacherPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;

export default SingleTeacherPage;
