"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { useForm } from "react-hook-form"
import { z } from "zod"
import InputField from "../InputField"
import { router } from "@inertiajs/react" // Import Inertia's router
import { useEffect, useState } from "react"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"

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
})

const StudentForm = ({ type, data, levels, classes, schools, setOpen }) => {
  console.log("form levels", levels)

  const defaultBillingDate = new Date().toISOString().split("T")[0]

  // State to track selected values for shadcn/ui Select components
  const [selectedLevel, setSelectedLevel] = useState(data?.levelId?.toString() || "")
  const [selectedClass, setSelectedClass] = useState(data?.classId?.toString() || "")
  const [selectedSchool, setSelectedSchool] = useState(data?.schoolId?.toString() || "")
  const [selectedStatus, setSelectedStatus] = useState(data?.status || "active")
  const [selectedAssurance, setSelectedAssurance] = useState(data?.assurance === 1 ? "1" : "0")

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
  })

  // Set default values when data is available
  useEffect(() => {
    if (data) {
      setValue("levelId", data.levelId?.toString())
      setValue("classId", data.classId?.toString())
      setValue("schoolId", data.schoolId?.toString())
      setSelectedLevel(data.levelId?.toString())
      setSelectedClass(data.classId?.toString())
      setSelectedSchool(data.schoolId?.toString())
      setSelectedStatus(data.status)
      setSelectedAssurance(data.assurance === 1 ? "1" : "0")
    }
  }, [data, setValue])

  // Handle form submission
  const onSubmit = (formData) => {
    // Convert assurance to 1 (true) or 0 (false) before submitting
    const updatedFormData = {
      ...formData,
      assurance: formData.assurance === "1" ? 1 : 0,
      levelId: formData.levelId.toString(),
      classId: formData.classId.toString(),
      schoolId: formData.schoolId.toString(),
    }

    if (type === "create") {
      // Send a POST request to create a new student
      router.post("/students", updatedFormData, {
        onSuccess: () => setOpen(false),
      })
    } else if (type === "update") {
      // Send a PUT request to update an existing student
      router.put(`/students/${data.id}`, updatedFormData, {
        onSuccess: () => setOpen(false),
      })
    }
  }

  return (
    <form className="flex flex-col gap-8 p-6 " onSubmit={handleSubmit(onSubmit)}>
      <h1 className="text-2xl font-semibold text-gray-800">
        {type === "create" ? "Create a new student" : "Update student"}
      </h1>
      <span className="text-xs text-gray-400 font-medium">Student Information</span>

      <div className="grid grid-cols-1 lg:grid-cols-3 md:grid-cols-2 gap-4">
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

      <div className="grid grid-cols-1 lg:grid-cols-3 md:grid-cols-2 gap-4">
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

      <div className="grid grid-cols-1 lg:grid-cols-3 md:grid-cols-2 gap-4">
        <InputField label="CIN" name="CIN" register={register} error={errors.CIN} defaultValue={data?.CIN} />
        <InputField
          label="Phone Number"
          name="phoneNumber"
          register={register}
          error={errors.phoneNumber}
          defaultValue={data?.phoneNumber}
        />
        <InputField label="Email" name="email" register={register} error={errors.email} defaultValue={data?.email} />
      </div>

      <span className="text-xs text-gray-400 font-medium">Enrollment Information</span>

      <div className="grid grid-cols-1 lg:grid-cols-3 md:grid-cols-2 gap-4">
        <InputField
          label="Massar Code"
          name="massarCode"
          register={register}
          error={errors.massarCode}
          defaultValue={data?.massarCode}
        />

        {/* Level Selection with shadcn/ui */}
        <div className="flex flex-col gap-2 w-full">
          <label className="text-xs text-gray-600">Level</label>
          <Select
            value={selectedLevel}
            onValueChange={(value) => {
              setSelectedLevel(value)
              setValue("levelId", value)
            }}
          >
            <SelectTrigger className="w-full bg-white ring-1 ring-gray-300 p-2 rounded-md text-sm">
              <SelectValue placeholder="Select Level" />
            </SelectTrigger>
            <SelectContent>
              {levels?.map((level) => (
                <SelectItem key={level.id} value={level.id.toString()}>
                  {level.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {errors.levelId?.message && <p className="text-xs text-red-400">{errors.levelId.message}</p>}
        </div>

        {/* Class Selection with shadcn/ui */}
        <div className="flex flex-col gap-2 w-full">
          <label className="text-xs text-gray-600">Class</label>
          <Select
            value={selectedClass}
            onValueChange={(value) => {
              setSelectedClass(value)
              setValue("classId", value)
            }}
          >
            <SelectTrigger className="w-full bg-white ring-1 ring-gray-300 p-2 rounded-md text-sm">
              <SelectValue placeholder="Select Class" />
            </SelectTrigger>
            <SelectContent>
              {classes?.map((classe) => (
                <SelectItem key={classe.id} value={classe.id.toString()}>
                  {classe.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {errors.classId?.message && <p className="text-xs text-red-400">{errors.classId.message}</p>}
        </div>

        {/* School Selection with shadcn/ui */}
        <div className="flex flex-col gap-2 w-full">
          <label className="text-xs text-gray-600">School</label>
          <Select
            value={selectedSchool}
            onValueChange={(value) => {
              setSelectedSchool(value)
              setValue("schoolId", value)
            }}
          >
            <SelectTrigger className="w-full bg-white ring-1 ring-gray-300 p-2 rounded-md text-sm">
              <SelectValue placeholder="Select School" />
            </SelectTrigger>
            <SelectContent>
              {schools?.map((school) => (
                <SelectItem key={school.id} value={school.id.toString()}>
                  {school.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          {errors.schoolId?.message && <p className="text-xs text-red-400">{errors.schoolId.message}</p>}
        </div>

        {/* Status Selection with shadcn/ui */}
        <div className="flex flex-col gap-2 w-full">
          <label className="text-xs text-gray-600">Status</label>
          <Select
            value={selectedStatus}
            onValueChange={(value) => {
              setSelectedStatus(value)
              setValue("status", value)
            }}
          >
            <SelectTrigger className="w-full bg-white ring-1 ring-gray-300 p-2 rounded-md text-sm">
              <SelectValue placeholder="Select Status" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="active">Active</SelectItem>
              <SelectItem value="inactive">Inactive</SelectItem>
            </SelectContent>
          </Select>
          {errors.status?.message && <p className="text-xs text-red-400">{errors.status.message}</p>}
        </div>

        {/* Assurance Selection with shadcn/ui */}
        <div className="flex flex-col gap-2 w-full">
          <label className="text-xs text-gray-600">Assurance</label>
          <Select
            value={selectedAssurance}
            onValueChange={(value) => {
              setSelectedAssurance(value)
              setValue("assurance", value)
            }}
          >
            <SelectTrigger className="w-full bg-white ring-1 ring-gray-300 p-2 rounded-md text-sm">
              <SelectValue placeholder="Select Assurance" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="1">Yes</SelectItem>
              <SelectItem value="0">No</SelectItem>
            </SelectContent>
          </Select>
          {errors.assurance?.message && <p className="text-xs text-red-400">{errors.assurance.message}</p>}
        </div>
      </div>

      <button className="bg-blue-400 text-white py-2 px-3 rounded-md mt-6 hover:bg-blue-500 transition duration-200">
        {type === "create" ? "Create" : "Update"}
      </button>
    </form>
  )
}

export default StudentForm

