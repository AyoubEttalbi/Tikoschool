import React, { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { router } from "@inertiajs/react";
import { Book, Percent, UserCheck, CheckCircle, XCircle } from "lucide-react";

const membershipSchema = z.object({
  teachers: z.record(
    z.object({
      teacherId: z.string().min(1, { message: "Please select a teacher!" }),
    })
  ),
});

const MembershipForm = ({ type, data, offers = [], teachers = [], setOpen, studentId }) => {
  const [selectedOffer, setSelectedOffer] = useState(null);
  const [selectedSubjects, setSelectedSubjects] = useState([]);
  const [amounts, setAmounts] = useState({});

  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm({
    resolver: zodResolver(membershipSchema),
    defaultValues: data || {},
  });

  useEffect(() => {
    if (type === "update" && data?.offerName) {
      const offer = offers.find((o) => o.offer_name === data.offerName);
      if (offer) {
        setSelectedOffer(offer);
        setSelectedSubjects(offer.subjects);
        offer.subjects.forEach((subject) => {
          const teacherData = data.teachers?.find((t) => t.subject === subject);
          if (teacherData) {
            setValue(`teachers.${subject}.teacherId`, teacherData.teacherId || "");
          }
        });
      }
    }
  }, [type, data, offers, setValue]);

  const handleOfferChange = (event) => {
    const offer = offers.find((o) => o.id === parseInt(event.target.value));
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

  const calculateAmounts = (offer) => {
    const newAmounts = {};
    offer.subjects.forEach((subject) => {
      const percentage = offer?.percentage?.[subject] || 0;
      newAmounts[subject] = (offer.price * percentage) / 100;
    });
    return newAmounts;
  };

  const getTeachersForSubject = (subject) => teachers.filter((t) => t.subjects?.includes(subject));

  const onSubmit = (formData) => {
    const finalData = {
      student_id: studentId,
      offer_id: selectedOffer.id,
      teachers: Object.entries(formData.teachers).map(([subject, { teacherId }]) => ({
        subject,
        teacherId,
        percentage: selectedOffer.percentage[subject],
        amount: amounts[subject] || 0,
      })),
    };

    if (type === "create") {
      router.post("/memberships", finalData, { onSuccess: () => setOpen(false) });
    } else if (type === "update") {
      router.put(`/memberships/${data.id}`, finalData);
    }
  };

  return (
    <form className="p-6 bg-white rounded-lg shadow-md border" onSubmit={handleSubmit(onSubmit)}>
      <h1 className="text-2xl font-bold text-gray-800 mb-6 flex items-center gap-2">
        <Book className="w-6 h-6 text-blue-600" />
        {type === "create" ? "Create New Membership" : "Update Membership"}
      </h1>

      <div className="mb-6">
        <label className="block text-sm font-medium text-gray-600 mb-2">Select Offer</label>
        <select {...register("offerName")} onChange={handleOfferChange} className="w-full px-4 py-2 border rounded-md">
          <option value="">Select Offer</option>
          {offers.map((offer) => (
            <option key={offer.id} value={offer.id}>{`${offer.offer_name} - ${offer.price} DH`}</option>
          ))}
        </select>
      </div>

      {selectedSubjects.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {selectedSubjects.map((subject) => (
            <div key={subject} className="bg-gray-50 p-4 rounded-lg shadow-sm border">
              <div className="flex items-center gap-2 mb-2">
                <UserCheck className="w-5 h-5 text-blue-600" />
                <span className="text-sm font-medium text-gray-700">{subject}</span>
              </div>
              <select {...register(`teachers.${subject}.teacherId`)} className="w-full px-4 py-2 border rounded-md">
                <option value="">Select Teacher</option>
                {getTeachersForSubject(subject).map((teacher) => (
                  <option key={teacher.id} value={teacher.id}>{teacher.first_name} {teacher.last_name}</option>
                ))}
              </select>
              <div className="flex justify-between mt-2 text-sm">
                <span className="flex items-center gap-1 text-gray-600">
                  <Percent className="w-4 h-4 text-blue-600" /> {selectedOffer.percentage[subject]}%
                </span>
                <span className="flex items-center gap-1 text-gray-600">
                  <CheckCircle className="w-4 h-4 text-blue-600" /> {amounts[subject]?.toFixed(2)} DH
                </span>
              </div>
            </div>
          ))}
        </div>
      )}

      <button type="submit" className="mt-6 w-full bg-blue-600 text-white py-3 rounded-md flex items-center justify-center gap-2 hover:bg-blue-700 transition-all">
        {type === "create" ? <CheckCircle className="w-5 h-5" /> : <XCircle className="w-5 h-5" />}
        {type === "create" ? "Create Membership" : "Update Membership"}
      </button>
    </form>
  );
};

export default MembershipForm;
