import React, { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { router } from "@inertiajs/react";
import { Book, Percent, UserCheck, CheckCircle, XCircle, Trash2 } from "lucide-react";

// Define the Zod schema for form validation
const membershipSchema = z.object({
  teachers: z.record(
    z.object({
      teacherId: z.string().min(1, { message: "Please select a teacher!" }),
    })
  ),
});

const MembershipForm = ({ type, data, offers = [], teachers = [], setOpen, studentId }) => {
  console.log('data', data);
  console.log('studentId', studentId);
  const [selectedOffer, setSelectedOffer] = useState(null);
  const [selectedSubjects, setSelectedSubjects] = useState([]);
  const [amounts, setAmounts] = useState({});

  // Initialize react-hook-form with Zod validation
  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
    reset,
  } = useForm({
    resolver: zodResolver(membershipSchema),
    defaultValues: {
      teachers: {}
    },
  });

  // Initialize form values when editing
  useEffect(() => {
    if (type === "update" && data) {
      // Find the selected offer
      const offer = offers.find(o => o.id === data.offer_id);
      
      if (offer) {
        setSelectedOffer(offer);
        setSelectedSubjects(offer.subjects);
        setAmounts(calculateAmounts(offer));
        
        // Set initial values for teachers
        const teachersObj = {};
        data.teachers.forEach(teacher => {
          teachersObj[teacher.subject] = {
            teacherId: teacher.teacherId
          };
        });
        
        // Update form values
        reset({ teachers: teachersObj });
      }
    }
  }, [data, offers, type, reset]);

  // Handle offer selection change
  const handleOfferChange = (event) => {
    const offerId = parseInt(event.target.value);
    const offer = offers.find((o) => o.id === offerId);
    if (offer) {
      setSelectedOffer(offer);
      setSelectedSubjects(offer.subjects);
      setAmounts(calculateAmounts(offer));
    } else {
      setSelectedOffer(null);
      setSelectedSubjects([]);
      setAmounts({});
    }
  };

  // Calculate amounts based on offer percentage
  const calculateAmounts = (offer) => {
    const newAmounts = {};
    offer.subjects.forEach((subject) => {
      const percentage = offer?.percentage?.[subject] || 0;
      newAmounts[subject] = (offer.price * percentage) / 100;
    });
    return newAmounts;
  };

  // Filter teachers by subject
  const getTeachersForSubject = (subject) =>
    teachers.filter((t) => t.subjects?.includes(subject));

  // Handle form submission
  const onSubmit = (formData) => {
    console.log("Form submitted with data:", formData);

    if (!selectedOffer) {
      alert("Please select an offer");
      return;
    }

    // Prepare data differently based on form type
    let finalData;
    
    if (type === "create") {
      // For create, use the full structure with all fields
      finalData = {
        student_id: studentId,
        offer_id: selectedOffer.id,
        teachers: Object.keys(formData.teachers).map((subject) => ({
          subject,
          teacherId: formData.teachers[subject].teacherId,
          percentage: selectedOffer.percentage[subject],
          amount: amounts[subject] || 0,
        })),
      };
    } else if (type === "update") {
      // For update, use the structure from the data prop, but update teacherId values
      finalData = {
        student_id: studentId,
        offer_id: selectedOffer.id,
        teachers: selectedSubjects.map(subject => {
          // Find the matching teacher entry in the original data if available
          const originalTeacher = data.teachers.find(t => t.subject === subject);
          
          return {
            subject,
            teacherId: formData.teachers[subject]?.teacherId,
            percentage: selectedOffer.percentage[subject],
            amount: amounts[subject] || 0,
          };
        }),
      };
    }

    console.log("Final data to send:", finalData);

    // Send API request based on form type
    if (type === "create") {
      router.post("/memberships", finalData, {
        onSuccess: () => setOpen(false),
        onError: (errors) => console.error("Create failed:", errors),
      });
    } else if (type === "update") {
      router.put(`/memberships/${data.id}`, finalData, {
        onSuccess: () => setOpen(false),
        onError: (errors) => console.error("Update failed:", errors),
      });
    }
  };

  // Handle membership deletion
  const handleDelete = () => {
    if (confirm("Are you sure you want to delete this membership?")) {
      router.delete(`/memberships/${data.id}`, {
        onSuccess: () => setOpen(false),
        onError: (errors) => console.error("Delete failed:", errors),
      });
    }
  };

  return (
    <form className="p-6 bg-white rounded-lg shadow-md border" onSubmit={handleSubmit(onSubmit)}>
      {/* Form Header */}
      <h1 className="text-2xl font-bold text-gray-800 mb-6 flex items-center gap-2">
        <Book className="w-6 h-6 text-blue-600" />
        {type === "create" ? "Create New Membership" : "Update Membership"}
      </h1>

      {/* Offer Selection */}
      <div className="mb-6">
        <label className="block text-sm font-medium text-gray-600 mb-2">Select Offer</label>
        <select
          onChange={handleOfferChange}
          className="w-full px-4 py-2 border rounded-md"
          defaultValue={data?.offer_id || ""}
        >
          <option value="">Select Offer</option>
          {offers.map((offer) => (
            <option key={offer.id} value={offer.id}>{`${offer.offer_name} - ${offer.price} DH`}</option>
          ))}
        </select>
      </div>

      {/* Teacher Selection for Each Subject */}
      {selectedSubjects.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {selectedSubjects.map((subject) => {
            const defaultTeacher = data?.teachers?.find(t => t.subject === subject)?.teacherId || "";
            return (
              <div key={subject} className="bg-gray-50 p-4 rounded-lg shadow-sm border">
                <div className="flex items-center gap-2 mb-2">
                  <UserCheck className="w-5 h-5 text-blue-600" />
                  <span className="text-sm font-medium text-gray-700">{subject}</span>
                </div>
                <select
                  {...register(`teachers.${subject}.teacherId`)}
                  className="w-full px-4 py-2 border rounded-md"
                  defaultValue={defaultTeacher}
                >
                  <option value="">Select Teacher</option>
                  {getTeachersForSubject(subject).map((teacher) => (
                    <option key={teacher.id} value={teacher.id}>
                      {teacher.first_name} {teacher.last_name}
                    </option>
                  ))}
                </select>
                <div className="flex justify-between mt-2 text-sm">
                  <span className="flex items-center gap-1 text-gray-600">
                    <Percent className="w-4 h-4 text-blue-600" /> {selectedOffer?.percentage?.[subject] || 0}%
                  </span>
                  <span className="flex items-center gap-1 text-gray-600">
                    <CheckCircle className="w-4 h-4 text-blue-600" /> {amounts[subject]?.toFixed(2) || 0} DH
                  </span>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Action Buttons */}
      <div className={`flex flex-row ${type === "create" ? "justify-center" : "justify-around"}`}>
        <button
          type="submit"
          className="mt-6 w-1/3 bg-blue-600 text-white py-3 rounded-md flex items-center justify-center gap-2 hover:bg-blue-700 transition-all"
        >
          {type === "create" ? <CheckCircle className="w-5 h-5" /> : <XCircle className="w-5 h-5" />}
          {type === "create" ? "Create Membership" : "Update Membership"}
        </button>
        {type !== "create" && (
          <button
            type="button"
            onClick={handleDelete}
            className="mt-6 w-1/3 bg-red-600 text-white py-3 rounded-md flex items-center justify-center gap-2 hover:bg-red-700 transition-all"
          >
            <Trash2 className="w-5 h-5" /> Delete Membership
          </button>
        )}
      </div>
    </form>
  );
};

export default MembershipForm;