import React from "react";
import {
    Clock,
    Edit,
    GraduationCap,
    Users,
    DollarSign,
    Calendar,
    UserCheck,
    Check,
    X,
    AlertCircle,
} from "lucide-react";
import FormModal from "./FormModal";
import { usePage } from "@inertiajs/react";

export default function MembershipCard({
    Student_memberships = [],
    teachers = [],
    offers = [],
    studentId,
}) {
    const role = usePage().props.auth.user.role;
    // Helper function to find a teacher's name by ID
    const getTeacherName = (teacherId) => {
        const teacher = teachers.find((t) => t.id === parseInt(teacherId));
        return teacher
            ? `${teacher.first_name} ${teacher.last_name}`
            : "Enseignant inconnu";
    };
    // Helper function to check if the membership has an unpaid invoice for the current month
    const hasUnpaidInvoice = (membership) => {
        return membership.payment_status !== "paid";
    };

    return (
        <div className="space-y-4">
            {Student_memberships.map((membership) => (
                <div
                    key={membership.id}
                    className="bg-gray-50 rounded-lg shadow-sm p-4 mb-4 border border-gray-300"
                >
                    <div className="flex justify-between items-start">
                        <div className="space-y-3">
                            <div className="flex items-center gap-2 text-gray-800 font-medium">
                                <GraduationCap className="h-5 w-5 text-gray-600" />
                                <span>
                                    Offre :{" "}
                                    <span className="text-gray-900 font-semibold">
                                        {membership.offer_name}
                                    </span>
                                </span>
                                {hasUnpaidInvoice(membership) ? (
                                    <div className="flex items-center">
                                        <AlertCircle className="h-5 w-5 text-amber-500" />
                                        <span className="text-xs text-amber-600 ml-1">
                                            Impayé
                                        </span>
                                    </div>
                                ) : (
                                    <Check className="h-5 w-5 text-green-500" />
                                )}
                            </div>
                            <div className="space-y-2">
                                <div className="flex items-center gap-2 text-gray-700 font-medium">
                                    <Users className="h-5 w-5 text-gray-600" />
                                    <span>Enseignants :</span>
                                </div>
                                <div className="ml-6 space-y-1">
                                    {membership.teachers.map(
                                        (teacher, index) => (
                                            <div
                                                key={index}
                                                className="flex items-center gap-2 text-gray-600"
                                            >
                                                <UserCheck className="h-4 w-4 text-gray-500" />
                                                <span>
                                                    {teacher.subject}:{" "}
                                                    <span className="text-gray-800 font-medium">
                                                        {getTeacherName(
                                                            teacher.teacherId,
                                                        )}
                                                    </span>
                                                </span>
                                                {role === "admin" && (
                                                    <span className="text-gray-500">
                                                        (Montant :{" "}
                                                        {teacher.amount
                                                            ? teacher.amount
                                                            : "0"}{" "}
                                                        DH)
                                                    </span>
                                                )}
                                            </div>
                                        ),
                                    )}
                                </div>
                            </div>
                        </div>
                        <FormModal
                            table="membership"
                            type="update"
                            teachers={teachers}
                            data={membership}
                            id={membership.id}
                            studentId={studentId}
                            offers={offers}
                        />
                    </div>
                    <div className="flex items-center gap-1 text-gray-500 text-sm mt-3 justify-end">
                        <Calendar className="h-4 w-4" />
                        <span>
                            {new Date(membership.created_at).toLocaleDateString(
                                "fr-FR",
                            )}
                        </span>
                    </div>
                </div>
            ))}
        </div>
    );
}
