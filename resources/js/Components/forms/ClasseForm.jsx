import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import InputField from "../InputField";
import { router } from "@inertiajs/react"; // Import Inertia's router

// Define the schema for the class form
const schema = z.object({
  name: z.string().min(1, { message: "Class name is required!" }),
  level_id: z.string().min(1, { message: "Level is required!" }),

});

const ClassesForm = ({ type, data, levels, groups, studentCount, teacherCount }) => {
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm({
    resolver: zodResolver(schema),
    defaultValues: {
      name: data?.name || "",
      level_id: data?.level_id || "",

    },
  });

  // Handle form submission
  const onSubmit = (formData) => {
    if (type === "create") {
      // Send a POST request to create a new class
      router.post("/classes", formData);
    } else if (type === "update") {
      // Send a PUT request to update an existing class
      router.put(`/classes/${data.id}`, formData);
    }
  };

  return (
    <form
      className="flex flex-col gap-8 p-6 bg-white shadow-lg rounded-lg"
      onSubmit={handleSubmit(onSubmit)}
    >
      <h1 className="text-2xl font-semibold text-gray-800">
        {type === "create" ? "Create a New Class" : "Update Class"}
      </h1>

      {/* Class Information */}
      <span className="text-xs text-gray-400 font-medium">Class Information</span>

      <div className="flex flex-wrap gap-4">
        <InputField
          label="Class Name"
          name="name"
          register={register}
          error={errors.name}
          defaultValue={data?.name}
        />
    <div className="flex flex-col gap-2 w-full md:w-1/4">
        <label className="text-xs text-gray-600">Level</label>
        <select
          className="ring-[1.5px] ring-gray-300 p-2 rounded-md text-sm w-full"
          {...register("level_id")}
          defaultValue={data?.level_id}
        >
          <option value="">Select Level</option>
          {levels?.map((level) => (
            <option key={level.id} value={level.id}>
              {level.name}
            </option>
          ))}
        </select>
        {errors.level_id?.message && <p className="text-xs text-red-400">{errors.level_id.message}</p>}
      </div>

      </div>

      <button
        type="submit"
        className="bg-blue-500 text-white p-3 rounded-md mt-6 hover:bg-blue-600 transition duration-200"
      >
        {type === "create" ? "Create" : "Update"}
      </button>
    </form>
  );
};

export default ClassesForm;