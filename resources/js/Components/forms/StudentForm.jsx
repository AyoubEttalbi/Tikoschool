import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import InputField from "../InputField";
import { router } from "@inertiajs/react"; // Import Inertia's router
import { useEffect } from "react";

// Define the schema with assurance as a boolean
const schema = z.object({
  firstName: z.string().min(1, { message: "First name is required!" }),
  lastName: z.string().min(1, { message: "Last name is required!" }),
  dateOfBirth: z.string().min(1, { message: "Date of birth is required!" }),
  billingDate: z.string().min(1, { message: "Billing date is required!" }),
  address: z.string().min(1, { message: "Address is required!" }),
  guardianNumber: z.string().min(1, { message: "Guardian name is required!" }),
  CIN: z.string().min(1, { message: "CIN is required!" }),
  phoneNumber: z.string().min(1, { message: "Phone number is required!" }),
  email: z.string().email({ message: "Invalid email address!" }),
  massarCode: z.string().min(1, { message: "Massar code is required!" }),
  levelId: z.string().min(1, { message: "Level is required!" }), // Only check for non-empty
  classId: z.string().min(1, { message: "Class is required!" }), // Only check for non-empty
  schoolId: z.string().min(1, { message: "School is required!" }),
  status: z.enum(["active", "inactive"], { message: "Status is required!" }),

  assurance: z.any().optional(),
});

const StudentForm = ({ type, data, levels, classes, schools }) => {
  console.log("form levels", levels);

  const defaultBillingDate = new Date().toISOString().split("T")[0];

  const {
    register,
    handleSubmit,
    formState: { errors },
    setValue,
  } = useForm({
    resolver: zodResolver(schema),
    defaultValues: {
      billingDate: defaultBillingDate,
      assurance: data?.assurance === 1 ? "1" : "0",
      ...data, // Spread existing data for update
    },
  });

  // Set default values when data is available
  useEffect(() => {
    if (data) {
      setValue("levelId", data.levelId?.toString());
      setValue("classId", data.classId?.toString());
      setValue("schoolId", data.schoolId?.toString());
    }
  }, [data, setValue]);
  // Handle form submission
  const onSubmit = (formData) => {
    // Convert assurance to 1 (true) or 0 (false) before submitting
    const updatedFormData = { ...formData, assurance: formData.assurance === "1" ? 1 : 0,levelId: formData.levelId.toString(), 
      classId: formData.classId.toString(), 
      schoolId: formData.schoolId.toString(), };

    if (type === "create") {
      // Send a POST request to create a new student
      router.post("/students", updatedFormData);
    } else if (type === "update") {
      // Send a PUT request to update an existing student
      router.put(`/students/${data.id}`, updatedFormData);
    }
  };

  return (
    <form
      className="flex flex-col gap-8 p-6 bg-white shadow-lg rounded-lg"
      onSubmit={handleSubmit(onSubmit)}
    >
      <h1 className="text-2xl font-semibold text-gray-800">
        {type === "create" ? "Create a new student" : "Update student"}
      </h1>
      <span className="text-xs text-gray-400 font-medium">Student Information</span>

      <div className="flex justify-between flex-wrap gap-4">
        <InputField
          label="First Name"
          name="firstName"
          register={register}
          error={errors.firstName}
          defaultValue={data?.firstName}
        />
        <InputField
          label="Last Name"
          name="lastName"
          register={register}
          error={errors.lastName}
          defaultValue={data?.lastName}
        />
        <InputField
          label="Date of Birth"
          name="dateOfBirth"
          register={register}
          error={errors.dateOfBirth}
          type="date"
          defaultValue={data?.dateOfBirth}
        />
      </div>

      <span className="text-xs text-gray-400 font-medium">Additional Information</span>

      <div className="flex justify-between flex-wrap gap-4">
        <InputField
          label="Billing Date"
          name="billingDate"
          register={register}
          error={errors.billingDate}
          type="date"
          defaultValue={data?.billingDate || defaultBillingDate}
        />
        <InputField
          label="Address"
          name="address"
          register={register}
          error={errors.address}
          defaultValue={data?.address}
        />
        <InputField
          label="Guardian Number"
          name="guardianNumber"
          register={register}
          error={errors.guardianNumber}
          defaultValue={data?.guardianNumber}
        />
      </div>

      <span className="text-xs text-gray-400 font-medium">Contact Information</span>

      <div className="flex justify-between flex-wrap gap-4">
        <InputField
          label="CIN"
          name="CIN"
          register={register}
          error={errors.CIN}
          defaultValue={data?.CIN}
        />
        <InputField
          label="Phone Number"
          name="phoneNumber"
          register={register}
          error={errors.phoneNumber}
          defaultValue={data?.phoneNumber}
        />
        <InputField
          label="Email"
          name="email"
          register={register}
          error={errors.email}
          defaultValue={data?.email}
        />
      </div>

      <span className="text-xs text-gray-400 font-medium">Enrollment Information</span>

      <div className="flex justify-between flex-wrap gap-4">
        <InputField
          label="Massar Code"
          name="massarCode"
          register={register}
          error={errors.massarCode}
          defaultValue={data?.massarCode}
        />

        {/* Level Selection */}
        <div className="flex flex-col gap-2 w-full md:w-1/4">
          <label className="text-xs text-gray-600">Level</label>
          <select className="ring-1 ring-gray-300 p-2 rounded-md text-sm w-full" {...register("levelId")}>
            <option value="">Select Level</option>
            {levels?.map((level) => (
              <option key={level.id} value={level.id}>
                {level.name}
              </option>
            ))}
          </select>
          {errors.levelId?.message && <p className="text-xs text-red-400">{errors.levelId.message}</p>}
        </div>

        {/* Class Selection */}
        <div className="flex flex-col gap-2 w-full md:w-1/4">
          <label className="text-xs text-gray-600">Class</label>
          <select className="ring-1 ring-gray-300 p-2 rounded-md text-sm w-full" {...register("classId")}>
            <option value="">Select Class</option>
            {classes?.map((classe) => (
              <option key={classe.id} value={type === "update" ? classe.id.toString() : classe.id}>
                {classe.name}
              </option>
            ))}
          </select>
          {errors.classId?.message && <p className="text-xs text-red-400">{errors.classId.message}</p>}
        </div>

        {/* School Selection */}
        <div className="flex flex-col gap-2 w-full md:w-1/4">
          <label className="text-xs text-gray-600">School</label>
          <select className="ring-1 ring-gray-300 p-2 rounded-md text-sm w-full" {...register("schoolId")}>
            <option value="">Select School</option>
            {schools?.map((school) => (
              <option key={school.id} value={school.id}>
                {school.name}
              </option>
            ))}
          </select>
          {errors.schoolId?.message && <p className="text-xs text-red-400">{errors.schoolId.message}</p>}
        </div>
        <div className="flex flex-col gap-2 w-full md:w-1/4">
          <label className="text-xs text-gray-600">Status</label>
          <select
            className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-full"
            {...register("status")}
            defaultValue={data?.status}
          >
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          {errors.status?.message && <p className="text-xs text-red-400">{errors.status.message}</p>}
        </div>

        {/* Assurance */}
        <div className="flex flex-col gap-2 w-full md:w-1/4">
          <label className="text-xs text-gray-600">Assurance</label>
          <select
            className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-full"
            {...register("assurance")}
            defaultValue={data?.assurance === 1 ? "1" : "0"}
          >
            <option value="1">Yes</option>
            <option value="0">No</option>
          </select>
          {errors.assurance?.message && <p className="text-xs text-red-400">{errors.assurance.message}</p>}
        </div>
      </div>

      <button className="bg-blue-400 text-white p-3 rounded-md mt-6 hover:bg-blue-500 transition duration-200">
        {type === "create" ? "Create" : "Update"}
      </button>
    </form>
  );
};

export default StudentForm;