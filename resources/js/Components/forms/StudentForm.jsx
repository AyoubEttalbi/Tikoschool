import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import InputField from "../InputField";
import { router } from "@inertiajs/react"; // Import Inertia's router

// Define the schema with assurance as a boolean
const schema = z.object({
  firstName: z.string().min(1, { message: "First name is required!" }),
  lastName: z.string().min(1, { message: "Last name is required!" }),
  dateOfBirth: z.string().min(1, { message: "Date of birth is required!" }),
  billingDate: z.string().min(1, { message: "Billing date is required!" }),
  address: z.string().min(1, { message: "Address is required!" }),
  guardianName: z.string().min(1, { message: "Guardian name is required!" }),
  CIN: z.string().min(1, { message: "CIN is required!" }),
  phoneNumber: z.string().min(1, { message: "Phone number is required!" }),
  email: z.string().email({ message: "Invalid email address!" }),
  massarCode: z.string().min(1, { message: "Massar code is required!" }),
  levelId: z.number().min(1, { message: "Level ID is required!" }),
  status: z.enum(["active", "inactive"], { message: "Status is required!" }),
  assurance: z.boolean(), // Updated to boolean
});


const StudentForm = ({ type, data }) => {
  const defaultBillingDate = new Date().toISOString().split("T")[0];

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm({
    resolver: zodResolver(schema),
    defaultValues: {
      billingDate: defaultBillingDate,
      assurance: data?.assurance || false, // Set default value for assurance
      ...data, // Spread existing data for update
    },
  });

  // Handle form submission
  const onSubmit = (formData) => {
    if (type === "create") {
      // Send a POST request to create a new student
      router.post("/students", formData);
    } else if (type === "update") {
      // Send a PUT request to update an existing student
      router.put(`/students/${data.id}`, formData);
    }
  };

  return (
    <form className="flex flex-col gap-8 p-6 bg-white shadow-lg rounded-lg" onSubmit={handleSubmit(onSubmit)}>
      <h1 className="text-2xl font-semibold text-gray-800">
        {type === "create" ? "Create a new student" : "Update student"}
      </h1>
      <span className="text-xs text-gray-400 font-medium">Student Information</span>

      <div className="flex justify-between flex-wrap gap-4">
        <InputField label="First Name" name="firstName" register={register} error={errors.firstName} />
        <InputField label="Last Name" name="lastName" register={register} error={errors.lastName} />
        <InputField label="Date of Birth" name="dateOfBirth" register={register} error={errors.dateOfBirth} type="date" />
      </div>

      <span className="text-xs text-gray-400 font-medium">Additional Information</span>

      <div className="flex justify-between flex-wrap gap-4">
        <InputField label="Billing Date" name="billingDate" register={register} error={errors.billingDate} type="date" />
        <InputField label="Address" name="address" register={register} error={errors.address} />
        <InputField label="Guardian Name" name="guardianName" register={register} error={errors.guardianName} />
      </div>

      <span className="text-xs text-gray-400 font-medium">Contact Information</span>

      <div className="flex justify-between flex-wrap gap-4">
        <InputField label="CIN" name="CIN" register={register} error={errors.CIN} />
        <InputField label="Phone Number" name="phoneNumber" register={register} error={errors.phoneNumber} />
        <InputField label="Email" name="email" register={register} error={errors.email} />
      </div>

      <span className="text-xs text-gray-400 font-medium">Enrollment Information</span>

      <div className="flex justify-between flex-wrap gap-4">
        <InputField label="Massar Code" name="massarCode" register={register} error={errors.massarCode} />
        <InputField label="Level ID" name="levelId" register={register} error={errors.levelId} />

        <div className="flex flex-col gap-2 w-full md:w-1/4">
          <label className="text-xs text-gray-600">Status</label>
          <select className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-full" {...register("status")}>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          {errors.status?.message && <p className="text-xs text-red-400">{errors.status.message}</p>}
        </div>

        <div className="flex flex-col gap-2 w-full md:w-1/4">
          <label className="text-xs text-gray-600">Assurance</label>
          <select
            className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-full"
            {...register("assurance", { setValueAs: (value) => value === "true" })} // Convert string to boolean
          >
            <option value="true">Yes</option>
            <option value="false">No</option>
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