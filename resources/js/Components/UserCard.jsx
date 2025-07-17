import { useMemo } from "react";

const UserCard = ({ type, counts, totalCount, schoolId }) => {
    // Get the count for the selected school
    const count = useMemo(() => {
        if (schoolId && counts[schoolId]) {
            return counts[schoolId].count;
        }
        return totalCount;
    }, [schoolId, counts, totalCount]);

    // Helper to get the current school year dynamically
    const getCurrentSchoolYear = () => {
        const now = new Date();
        const year = now.getMonth() >= 7 ? now.getFullYear() : now.getFullYear() - 1;
        return `${year}/${(year + 1).toString().slice(-2)}`;
    };

    function getTypeLabel(type) {
        switch (type) {
            case "teacher":
                return "Enseignants";
            case "student":
                return "Étudiants";
            case "assistant":
                return "Assistants";
            default:
                return "";
        }
    }

    return (
        <div className="rounded-2xl odd:bg-lamaPurple even:bg-lamaYellow p-4 flex-1 min-w-[130px] relative">
            <div className="flex justify-between items-center">
                <span className="text-[10px] bg-white px-2 py-1 rounded-full text-green-600">
                    {getCurrentSchoolYear()}
                </span>
            </div>
            <h1 className="text-2xl font-semibold my-4">{count}</h1>
            <h2 className="capitalize text-sm font-medium text-gray-500">
                {getTypeLabel(type)}
            </h2>
        </div>
    );
};

export default UserCard;
