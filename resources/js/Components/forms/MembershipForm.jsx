import React, { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";

const membershipSchema = z.object({
  offerName: z.string().min(1, { message: "Offer is required!" }),
  teachers: z.record(
    z.string().min(1, { message: "Please select a teacher!" })
  ),
});

const offers = [
  { id: 1, name: "AC MATH SVT", subjects: ["Math", "SVT"] },
  { id: 2, name: "AC PHYSIQUE CHIMIE", subjects: ["Physique", "Chimie"] },
  { id: 3, name: "AC MATH PHYSIQUE", subjects: ["Math", "Physique"] },
];

const allTeachers = [
  { id: 1, name: "Hamouda Chakiri", subjects: ["Math", "SVT"] },
  { id: 2, name: "Ayoub El Mahdaoui", subjects: ["Math", "Physique"] },
  { id: 3, name: "Mohamed Amine", subjects: ["SVT", "Chimie"] },
  { id: 4, name: "Fatima Zahra", subjects: ["Physique", "Chimie"] },
];

const MembershipForm = ({ type, data }) => {
  const [selectedOffer, setSelectedOffer] = useState("");
  const [selectedSubjects, setSelectedSubjects] = useState([]);

  const {
    register,
    handleSubmit,
    formState: { errors },
    setValue,
  } = useForm({
    resolver: zodResolver(membershipSchema),
    defaultValues: data || {},
  });


  useEffect(() => {
    if (type === "update" && data?.offers) {
      const offer = offers.find((o) => o.id === data.offers.id);
      if (offer) {
        setSelectedOffer(offer.name);
        setSelectedSubjects(offer.subjects);

        setValue("offerName", offer.name);

        offer.subjects.forEach((subject) => {
          setValue(`teachers.${subject}`, data.teachers?.[subject] || "");
        });
      }
    }
  }, [type, data, setValue]);

  const handleOfferChange = (event) => {
    const offerName = event.target.value;
    setSelectedOffer(offerName);

    const offer = offers.find((o) => o.name === offerName);
    if (offer) {
      setSelectedSubjects(offer.subjects);
      offer.subjects.forEach((subject) => setValue(`teachers.${subject}`, ""));
    } else {
      setSelectedSubjects([]);
    }
  };

  const getTeachersForSubject = (subject) => {
    return allTeachers.filter((teacher) => teacher.subjects.includes(subject));
  };

  const onSubmit = (formData) => {
    console.log(formData);
  };

  return (
    <form
      className="flex flex-col gap-8 p-6 bg-white shadow-lg rounded-lg"
      onSubmit={handleSubmit(onSubmit)}
    >
      <h1 className="text-2xl font-semibold text-gray-800">
        {type === "create" ? "Create New Membership" : "Update Membership"}
      </h1>

      <span className="text-xs text-gray-400 font-medium">Membership Information</span>

      <div className="flex flex-wrap gap-4">
        <div className="flex flex-col gap-2 w-full md:w-1/2">
          <label className="text-xs text-gray-600">Offer Name</label>
          <select
            {...register("offerName")}
            onChange={handleOfferChange}
            className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-full"
            value={selectedOffer}
          >
            <option value="">Select Offer</option>
            {offers.map((offer) => (
              <option key={offer.id} value={offer.name}>
                {offer.name}
              </option>
            ))}
          </select>
          {errors.offerName && (
            <p className="text-xs text-red-400">{errors.offerName.message}</p>
          )}
        </div>
      </div>

      {selectedSubjects.length > 0 && (
        <>
          <span className="text-xs text-gray-400 font-medium">Assign Teachers for Subjects</span>
          <div className="flex flex-wrap gap-4">
            {selectedSubjects.map((subject) => (
              <div key={subject} className="flex flex-col gap-2 w-full md:w-1/3">
                <label className="text-xs text-gray-600">Teacher for {subject}</label>
                <select
                  {...register(`teachers.${subject}`)}
                  className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-full"
                >
                  <option value="">Select Teacher</option>
                  {getTeachersForSubject(subject).map((teacher) => (
                    <option key={teacher.id} value={teacher.name}>
                      {teacher.name}
                    </option>
                  ))}
                </select>
                {errors.teachers?.[subject] && (
                  <p className="text-xs text-red-400">
                    {errors.teachers[subject]?.message}
                  </p>
                )}
              </div>
            ))}
          </div>
        </>
      )}

      <button className="bg-blue-400 text-white p-3 rounded-md mt-6 hover:bg-blue-500 transition duration-200">
        {type === "create" ? "Create" : "Update"}
      </button>
    </form>
  );
};

export default MembershipForm;
