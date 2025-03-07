import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import InputField from "../InputField";
import { router } from "@inertiajs/react"; 

const schema = z.object({
  first_name: z.string().min(1, { message: "First name is required!" }),
  last_name: z.string().min(1, { message: "Last name is required!" }),
  phone_number: z.string().min(1, { message: "Phone number is required!" }),
  email: z.string().email({ message: "Invalid email address!" }),
  address: z.string().min(1, { message: "Address is required!" }),
  status: z.enum(["active", "inactive"], { message: "Status is required!" }),
});

const AssistantForm = ({ type, data }) => {

  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm({
    resolver: zodResolver(schema),
    defaultValues: {
      status: "active", 
      ...data, 
    },
  });

  const onSubmit = (formData) => {
    if (type === "create") {
      router.post("/assistants", formData);
    } else if (type === "update") {
      router.put(`/assistants/${data.id}`, formData);
    }
  };

  return (
    <form
      className="flex flex-col gap-6 p-6 bg-white shadow-lg rounded-lg"
      onSubmit={handleSubmit(onSubmit)}
    >
      <h1 className="text-2xl font-semibold text-gray-800">
        {type === "create" ? "Create Assistant" : "Update Assistant"}
      </h1>

      {/* Personal Information */}
      <span className="text-xs text-gray-400 font-medium">Assistant Information</span>

      <div className="flex flex-wrap gap-4">
        <InputField
          label="First Name"
          name="first_name"
          register={register}
          error={errors.first_name}
          defaultValue={data?.first_name}
        />
        <InputField
          label="Last Name"
          name="last_name"
          register={register}
          error={errors.last_name}
          defaultValue={data?.last_name}
        />
        <InputField
          label="Phone Number"
          name="phone_number"
          register={register}
          error={errors.phone_number}
          defaultValue={data?.phone_number}
        />
      </div>

    
      <span className="text-xs text-gray-400 font-medium">Contact Information</span>

      <div className="flex flex-wrap gap-4">
        <InputField
          label="Email"
          name="email"
          register={register}
          error={errors.email}
          defaultValue={data?.email}
        />
        <InputField
          label="Address"
          name="address"
          register={register}
          error={errors.address}
          defaultValue={data?.address}
        />
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

      <button className="bg-blue-500 text-white p-3 rounded-md mt-6 hover:bg-blue-600 transition duration-200">
        {type === "create" ? "Create" : "Update"}
      </button>
    </form>
  );
};

export default AssistantForm;
