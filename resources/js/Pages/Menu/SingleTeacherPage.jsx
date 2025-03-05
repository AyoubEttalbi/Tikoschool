import Announcements from "@/Components/Announcements";
import BigCalendar from "@/Components/BigCalender";
import FormModal from "@/Components/FormModal";
import Performance from "@/Components/Performance";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { role } from "@/lib/data";
import { Link } from "@inertiajs/react";

const SingleTeacherPage = ({ teacher }) => {
  return (
    <div className="flex-1 p-4 flex flex-col gap-4 xl:flex-row">
      {/* LEFT */}
      <div className="w-full xl:w-2/3">
        {/* TOP */}
        <div className="flex flex-col lg:flex-row gap-4">
          {/* USER INFO CARD */}
          <div className="bg-lamaSky py-6 px-4 rounded-md flex-1 flex gap-4">
            <div className="w-1/3">
              <img
                src={teacher.photo || "https://images.pexels.com/photos/2888150/pexels-photo-2888150.jpeg?auto=compress&cs=tinysrgb&w=1200"}
                alt={teacher.lastName}
                width={144}
                height={144}
                className="w-36 h-36 rounded-full object-cover"
              />
            </div>
            <div className="w-2/3 flex flex-col justify-between gap-4">
              <div className="flex items-center gap-4">
                <h1 className="text-xl font-semibold">{teacher.firstName} {teacher.lastName}</h1>
                
              </div>
              <p className="text-sm text-gray-500">
                {teacher.bio || "No bio available."}
              </p>
              <div className="flex items-center justify-between gap-2 flex-wrap text-xs font-medium">
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/blood.png" alt="" width={14} height={14} />
                  <span>{teacher.bloodType || "N/A"}</span>
                </div>
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/date.png" alt="" width={14} height={14} />
                  <span>{teacher.dateOfBirth || "N/A"}</span>
                </div>
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/mail.png" alt="" width={14} height={14} />
                  <span>{teacher.email}</span>
                </div>
                <div className="w-full md:w-1/3 lg:w-full 2xl:w-1/3 flex items-center gap-2">
                  <img src="/phone.png" alt="" width={14} height={14} />
                  <span>{teacher.phoneNumber}</span>
                </div>
              </div>
            </div>
            {role === "admin" && (
                  <FormModal
                    table="teacher"
                    type="update"
                    data={teacher}
                  />
                )}
          </div>

          {/* SMALL CARDS */}
          <div className="flex-1 flex gap-4 justify-between flex-wrap">
            <InfoCard icon="/singleAttendance.png" label="Number of Students" value="20" />
            <InfoCard icon="/singleBranch.png" label="Subjects" value="2" />
            <InfoCard icon="/singleLesson.png" label="Offers" value="6" />
            <InfoCard icon="/singleClass.png" label="Classes" value="6" />
          </div>
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
            <ShortcutLink label="Teacher's Classes" href="/" bgClass="bg-lamaSkyLight" />
            <ShortcutLink label="Teacher's Students" href="/" bgClass="bg-lamaPurpleLight" />
            <ShortcutLink label="Teacher's Lessons" href="/" bgClass="bg-lamaYellowLight" />
            <ShortcutLink label="Teacher's Exams" href="/" bgClass="bg-pink-50" />
            <ShortcutLink label="Teacher's Assignments" href="/" bgClass="bg-lamaSkyLight" />
          </div>
        </div>
        <Performance />
        <Announcements />
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
