
import { Link,usePage } from "@inertiajs/react";

const menuItems = [
  {
    title: "MENU",
    items: [
      {
        icon: "/home.png",
        label: "Home",
        href: "/dashboard",
        visible: ["admin", "teacher", "assistant"],
      },
      {
        icon: "/teacher.png",
        label: "Teachers",
        href: "/teachers",
        visible: ["admin", "teacher", "assistant"],
      },
      {
        icon: "/student.png",
        label: "Students",
        href: "/students",
        visible: ["admin", "teacher", "assistant"],
      },
      {
        icon: "/parent.png",
        label: "Assistants",
        href: "/assistants",
        visible: ["admin", "teacher"],
      },
      
      {
        icon: "/class.png",
        label: "Classes",
        href: "/classes",
        visible: ["admin", "teacher", "assistant"],
      },
      {
        icon: "/offer.png",
        label: "Offers",
        href: "/offers",
        visible: ["admin"],
      },
      {
        icon: "/exam.png",
        label: "Exams",
        href: "/exams",
        visible: ["admin", "teacher", "assistant"],
      },
      // {
      //   icon: "/assignment.png",
      //   label: "Assignments",
      //   href: "/assignments",
      //   visible: ["admin", "teacher", "student", "parent"],
      // },
      {
        icon: "/result.png",
        label: "Results",
        href: "/results",
        visible: ["admin", "teacher", "assistant"],
      },
      {
        icon: "/attendance.png",
        label: "Attendance",
        href: "/attendance",
        visible: ["admin", "teacher", "assistant"],
      },
     
      {
        icon: "/announcement.png",
        label: "Announcements",
        href: "/announcements",
        visible: ["admin", "teacher", "assistant"],
      },
      {
        icon: "/other_settings.png",
        label: "Other Settings",
        href: "/othersettings",
        visible: ["admin"],
      },
    ],
  },
  {
    title: "OTHER",
    items: [
      {
        icon: "/profile.png",
        label: "Profile",
        href: "/profile",
        visible: ["admin", "teacher", "student", "parent"],
      },
      {
        icon: "/setting.png",
        label: "Settings",
        href:  `/setting`,
        visible: ["admin"],
      },
      {  
        method: "post" ,
        as :"button",
        icon: "/logout.png",
        label: "Logout",
        href: `${route("logout")}`,
        visible: ["admin", "teacher", "assistant"],
      },
    ],
  },
];

const Menu = () => {
  const role=usePage().props.auth.user.role;
  return (
    <div className="mt-4 text-sm">
      {menuItems.map((i) => (
        <div className="flex flex-col gap-2" key={i.title}>
          <span className="hidden lg:block text-gray-400 font-light my-4">
            {i.title} 
          </span>
          {i.items.map((item) => {
            if (item.visible.includes(role)) {
              return (
                <Link
                  as={item.as}
                 method={item.method}
                  href={item.href}
                  key={item.label}
                  className="flex items-center justify-center lg:justify-start gap-4 text-gray-500 py-2 md:px-2 rounded-md hover:bg-lamaSkyLight"
                >
                  <img src={item.icon} alt="" width={20} height={20} />
                  <span className="hidden lg:block">{item.label}</span>
                </Link>
              );
            }
          })}
        </div>
      ))}
    </div>
  );
};

export default Menu;
