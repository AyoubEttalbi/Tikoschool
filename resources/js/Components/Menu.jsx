import { Link, usePage } from "@inertiajs/react";

const menuItems = [
    {
        title: "MENU",
        items: [
            {
                icon: "/home.png",
                label: "Accueil",
                href: "/dashboard",
                visible: ["admin", "teacher", "assistant"],
            },
            {
                icon: "/teacher.png",
                label: "Enseignants",
                href: "/teachers",
                visible: ["admin", "teacher", "assistant"],
            },
            {
                icon: "/student.png",
                label: "Élèves",
                href: "/students",
                visible: ["admin", "teacher", "assistant"],
            },
            {
                icon: "/assistant.png",
                label: "Assistants",
                href: "/assistants",
                visible: ["admin"],
            },
            {
                icon: "/class.png",
                label: "Classes",
                href: "/classes",
                visible: ["admin", "teacher", "assistant"],
            },
            {
                icon: "/class.png",
                label: "Classe-Enseignant",
                href: "/teacher-classes",
                visible: ["admin"],
            },
            {
                icon: "/offer.png",
                label: "Offres",
                href: "/offers",
                visible: ["admin"],
            },
            {
                icon: "/credit-card.png",
                label: "Paiements",
                href: "/transactions",
                visible: ["admin"],
            },
            {
                icon: "/cashier.png",
                label: "Caisse",
                href: "/cashier",
                visible: ["admin", "assistant"],
            },
            {
                icon: "/result.png",
                label: "Résultats",
                href: "/results",
                visible: ["admin", "teacher", "assistant"],
            },
            {
                icon: "/attendance.png",
                label: "Présences",
                href: "/attendances",
                visible: ["admin", "teacher", "assistant"],
            },
            {
                icon: "/announcement.png",
                label: "Annonces",
                href: "/announcements",
                visible: ["admin"],
            },
            {
                icon: "/other_settings.png",
                label: "Autres paramètres",
                href: "/othersettings",
                visible: ["admin"],
            },
        ],
    },
    {
        title: "AUTRE",
        items: [
            {
                icon: "/profile.png",
                label: "Profil",
                href: "/profile",
                visible: ["admin", "teacher", "assistant"],
            },
            {
                icon: "/setting.png",
                label: "Paramètres",
                href: `/setting`,
                visible: ["admin"],
            },
            {
                method: "post",
                as: "button",
                icon: "/logout.png",
                label: "Déconnexion",
                href: `${route("logout")}`,
                visible: ["admin", "teacher", "assistant"],
            },
        ],
    },
];

const Menu = () => {
    const role = usePage().props.auth.user.role;
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
                                    <img
                                        src={item.icon}
                                        alt=""
                                        width={20}
                                        height={20}
                                        className={`${item.label === "Assistants" ? "w-6 h-6 -ml-1" : ""}`}
                                    />
                                    <span className="hidden lg:block">
                                        {item.label}
                                    </span>
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
