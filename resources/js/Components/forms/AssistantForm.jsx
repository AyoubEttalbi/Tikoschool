import { zodResolver } from "@hookform/resolvers/zod";
import { useForm, Controller } from "react-hook-form";
import { z } from "zod";
import InputField from "../InputField";
import { router, Link } from "@inertiajs/react";
import Select from "react-select";

// Define the schema
const schema = z.object({
  first_name: z.string().min(1, { message: "First name is required!" }),
  last_name: z.string().min(1, { message: "Last name is required!" }),
  phone_number: z.string().min(1, { message: "Phone number is required!" }),
  email: z.string().email({ message: "Invalid email address!" }),
  address: z.string().min(1, { message: "Address is required!" }),
  status: z.enum(["active", "inactive"], { message: "Status is required!" }),
  salary: z.coerce.number().nonnegative({ message: "Salary must be positive!" }),
  profile_image: z.any().optional(), // For file uploads
  schools_assistant: z.array(z.object({ id: z.number(), name: z.string() })).optional(),
});

const AssistantForm = ({ type =[], data , schools  }) => {
  const {
    register,
    handleSubmit,
    control,
    formState: { errors },
  } = useForm({
    resolver: zodResolver(schema),
    defaultValues: {
      first_name: data?.first_name || "",
      last_name: data?.last_name || "",
      phone_number: data?.phone_number || "",
      email: data?.email || "",
      address: data?.address || "",
      status: data?.status || "active",
      salary: data?.salary || 0,
      profile_image: data?.profile_image || null,
      schools_assistant: data?.schools_assistant?.map(({ id, name }) => ({ id, name })) || [],
    },
  });

  const onSubmit = handleSubmit((formData) => {
    const formattedData = {
      first_name: formData.first_name,
      last_name: formData.last_name,
      phone_number: formData.phone_number,
      email: formData.email,
      address: formData.address,
      status: formData.status,
      salary: formData.salary,
      profile_image: formData.profile_image,
      schools: formData.schools_assistant.map((school) => school.id), // Send array of school IDs
    };
  
    if (type === "create") {
      router.post("/assistants", formattedData);
    } else if (type === "update") {
      router.put(`/assistants/${data.id}`, formattedData);
    }
  });

  return (
    <div className="flex flex-col gap-4">
      <form className="flex flex-col gap-8" onSubmit={onSubmit}>
        <h1 className="text-xl font-semibold">
          {type === "create" ? "Create a New Assistant" : "Update Assistant"}
        </h1>

        {/* Personal Information */}
        <div className="flex flex-wrap gap-4">
          <InputField label="First Name" name="first_name" register={register} error={errors.first_name} />
          <InputField label="Last Name" name="last_name" register={register} error={errors.last_name} />
          <InputField label="Phone Number" name="phone_number" register={register} error={errors.phone_number} />
          <InputField label="Email" name="email" register={register} error={errors.email} />
          <InputField label="Address" name="address" register={register} error={errors.address} />
      
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

          <InputField label="Salary" name="salary" type="number" register={register} error={errors.salary} />

          {/* File Upload for Profile Image */}
          {/* <div className="w-full md:w-1/4">
            <label className="text-xs text-gray-500">Profile Image</label>
            <input
              type="file"
              {...register("profile_image")}
              className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-full"
            />
            {errors.profile_image && <p className="text-xs text-red-400">{errors.profile_image.message}</p>}
          </div> */}

          {/* Multi-Select for Schools */}
          <div className="w-full md:w-1/4">
            <label className="text-xs text-gray-500">Schools</label>
            <Controller
              name="schools_assistant"
              control={control}
              render={({ field }) => (
                <Select
                  {...field}
                  options={schools}
                  isMulti
                  getOptionLabel={(e) => e.name}
                  getOptionValue={(e) => e.id.toString()}
                  onChange={(val) => field.onChange(val)}
                  className="basic-multi-select"
                  classNamePrefix="select"
                />
              )}
            />
            {errors.schools_assistant && <p className="text-xs text-red-400">{errors.schools_assistant.message}</p>}
          </div>
        </div>

        <button type="submit" className="bg-blue-500 text-white p-2 rounded-md">
          {type === "create" ? "Create" : "Update"}
        </button>
      </form>

      {type === "update" && (
        <Link href={`/setting?data=${JSON.stringify(data)}`}>
          <button className="bg-blue-500 w-full text-white p-2 rounded-md">Go to User</button>
        </Link>
      )}
    </div>
  );
};

export default AssistantForm;