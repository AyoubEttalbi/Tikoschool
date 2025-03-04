import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import InputField from "../InputField";

// Schema matching your database columns
const schema = z.object({
  firstName: z.string().min(1, { message: "First name is required!" }),
  lastName: z.string().min(1, { message: "Last name is required!" }),
  address: z.string().optional(),
  phoneNumber: z.string().optional(),
  email: z.string().email({ message: "Invalid email address!" }),
  status: z.enum(["active", "inactive"]),
  subjects: z.string().optional(), // You can handle parsing to JSON in the backend
  wallet: z.coerce.number().nonnegative({ message: "Wallet must be positive!" }),
  groupName: z.string().optional(),
});

const TeacherForm = ({ type, data }) => {
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm({
    resolver: zodResolver(schema),
    defaultValues: {
      firstName: data?.first_name || "",
      lastName: data?.last_name || "",
      address: data?.address || "",
      phoneNumber: data?.phone_number || "",
      email: data?.email || "",
      status: data?.status || "active",
      subjects: data?.subjects ? JSON.stringify(data.subjects) : "",
      wallet: data?.wallet || 0,
      groupName: data?.group_name || "",
    },
  });

  const onSubmit = handleSubmit((formData) => {
    console.log(formData);
    // You'd handle subjects parsing to JSON in backend if needed.
  });

  return (
    <form className="flex flex-col gap-8" onSubmit={onSubmit}>
      <h1 className="text-xl font-semibold">
        {type === "create" ? "Create a New Teacher" : "Update Teacher"}
      </h1>

      {/* Personal Information */}
      <span className="text-xs text-gray-400 font-medium">Personal Information</span>
      <div className="flex flex-wrap gap-4">
        <InputField label="First Name" name="firstName" register={register} error={errors.firstName} />
        <InputField label="Last Name" name="lastName" register={register} error={errors.lastName} />
        <InputField label="Address" name="address" register={register} error={errors.address} />
        <InputField label="Phone Number" name="phoneNumber" register={register} error={errors.phoneNumber} />
        <InputField label="Email" name="email" register={register} error={errors.email} />
      </div>

      {/* Additional Information */}
      <span className="text-xs text-gray-400 font-medium">Additional Information</span>
      <div className="flex flex-wrap gap-4">
        <div className="flex flex-col gap-2 w-full md:w-1/4">
          <label className="text-xs text-gray-500">Status</label>
          <select
            className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-full"
            {...register("status")}
          >
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
          {errors.status && <p className="text-xs text-red-400">{errors.status.message}</p>}
        </div>

        <InputField 
          label="Subjects (JSON format)" 
          name="subjects" 
          register={register} 
          error={errors.subjects} 
        />

        <InputField 
          label="Wallet Amount" 
          name="wallet" 
          type="number" 
          register={register} 
          error={errors.wallet} 
        />

        <InputField 
          label="Group Name" 
          name="groupName" 
          register={register} 
          error={errors.groupName} 
        />
      </div>

      <button type="submit" className="bg-blue-500 text-white p-2 rounded-md">
        {type === "create" ? "Create" : "Update"}
      </button>
    </form>
  );
};

export default TeacherForm;
