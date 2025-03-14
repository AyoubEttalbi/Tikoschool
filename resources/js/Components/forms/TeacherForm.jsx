"use client"

import { zodResolver } from "@hookform/resolvers/zod"
import { useForm } from "react-hook-form"
import { z } from "zod"
import { useState, useEffect } from "react"
import InputField from "../InputField"
import { router, Link } from "@inertiajs/react"
import { Check, ChevronsUpDown, X } from "lucide-react"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"

// Define the schema
const schema = z.object({
  firstName: z.string().min(1, { message: "First name is required!" }),
  lastName: z.string().min(1, { message: "Last name is required!" }),
  address: z.string().optional(),
  phoneNumber: z.string().optional(),
  email: z.string().email({ message: "Invalid email address!" }),
  status: z.enum(["active", "inactive"]),
  subjects: z.array(z.object({ id: z.number(), name: z.string() })).optional(),
  wallet: z.coerce.number().nonnegative({ message: "Wallet must be positive!" }),
  classes: z.array(z.object({ id: z.number(), name: z.string() })).optional(),
  schools: z.array(z.object({ id: z.number(), name: z.string() })).optional(),
})

const TeacherForm = ({ type, data, subjects, classes, schools, setOpen }) => {
  const [selectedStatus, setSelectedStatus] = useState(data?.status || "active")
  const [selectedSubjects, setSelectedSubjects] = useState(data?.subjects || [])
  const [selectedClasses, setSelectedClasses] = useState(data?.classes?.map(({ id, name }) => ({ id, name })) || [])
  const [selectedSchools, setSelectedSchools] = useState(data?.schools?.map(({ id, name }) => ({ id, name })) || [])

  const {
    register,
    handleSubmit,
    control,
    setValue,
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
      subjects: data?.subjects || [],
      wallet: data?.wallet || 0,
      classes: data?.classes?.map(({ id, name }) => ({ id, name })) || [],
      schools: data?.schools?.map(({ id, name }) => ({ id, name })) || [],
    },
  })

  // Update form values when selections change
  useEffect(() => {
    setValue("subjects", selectedSubjects)
    setValue("classes", selectedClasses)
    setValue("schools", selectedSchools)
    setValue("status", selectedStatus)
  }, [selectedSubjects, selectedClasses, selectedSchools, selectedStatus, setValue])

  const onSubmit = handleSubmit((formData) => {
    const formattedData = {
      first_name: formData.firstName,
      last_name: formData.lastName,
      address: formData.address,
      phone_number: formData.phoneNumber,
      email: formData.email,
      status: formData.status,
      wallet: formData.wallet,
      subjects: formData.subjects.map((subj) => subj.id),
      classes: formData.classes.map((group) => group.id),
      schools: formData.schools.map((school) => school.id),
    }

    console.log(formattedData)

    if (type === "create") {
      router.post("/teachers", formattedData, {
        onSuccess: () => {
          setOpen(false)
        },
      })
    } else if (type === "update") {
      router.put(`/teachers/${data.id}`, formattedData)
    }
  })

  // Helper function to toggle selection in multi-select
  const toggleSelection = (item, currentSelection, setSelection, fieldName) => {
    const isSelected = currentSelection.some((selected) => selected.id === item.id)

    let newSelection
    if (isSelected) {
      newSelection = currentSelection.filter((selected) => selected.id !== item.id)
    } else {
      newSelection = [...currentSelection, item]
    }

    setSelection(newSelection)
    setValue(fieldName, newSelection)
  }

  // Helper to remove an item from selection
  const removeItem = (item, currentSelection, setSelection, fieldName) => {
    const newSelection = currentSelection.filter((selected) => selected.id !== item.id)
    setSelection(newSelection)
    setValue(fieldName, newSelection)
  }

  return (
    <div className="flex flex-col gap-4">
      <form className="flex flex-col gap-8" onSubmit={onSubmit}>
        <h1 className="text-xl font-semibold">{type === "create" ? "Create a New Teacher" : "Update Teacher"}</h1>

        {/* Personal Information */}
        <div className="grid grid-cols-1 lg:grid-cols-3 md:grid-cols-2 gap-4">
          <InputField label="First Name" name="firstName" register={register} error={errors.firstName} />
          <InputField label="Last Name" name="lastName" register={register} error={errors.lastName} />
          <InputField label="Address" name="address" register={register} error={errors.address} />
          <InputField label="Phone Number" name="phoneNumber" register={register} error={errors.phoneNumber} />
          <InputField label="Email" name="email" register={register} error={errors.email} />
          <InputField label="Wallet Amount" name="wallet" type="number" register={register} error={errors.wallet} />
        </div>

        {/* Additional Information */}
        <div className="flex flex-wrap gap-4">
          {/* Status with shadcn/ui Select */}
          <div className="flex flex-col gap-2 w-full md:w-1/4">
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
            {errors.status && <p className="text-xs text-red-400">{errors.status.message}</p>}
          </div>

          {/* Multi-Select for Subjects with shadcn/ui */}
          <div className="flex flex-col gap-2 w-full md:w-1/4">
            <label className="text-xs text-gray-600">Subjects</label>
            <Popover>
              <PopoverTrigger asChild>
                <Button
                  variant="outline"
                  className="w-full justify-between bg-white ring-1 ring-gray-300 p-2 rounded-md text-sm h-auto min-h-10"
                >
                  {selectedSubjects.length > 0 ? `${selectedSubjects.length} selected` : "Select subjects"}
                  <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
              </PopoverTrigger>
              <PopoverContent className="w-full p-0" align="start">
                <Command>
                  <CommandInput placeholder="Search subjects..." />
                  <CommandList>
                    <CommandEmpty>No subjects found.</CommandEmpty>
                    <CommandGroup>
                      {subjects?.map((subject) => (
                        <CommandItem
                          key={subject.id}
                          onSelect={() => toggleSelection(subject, selectedSubjects, setSelectedSubjects, "subjects")}
                          className="flex items-center gap-2"
                        >
                          <div
                            className={`flex-shrink-0 rounded-full p-1 ${
                              selectedSubjects.some((s) => s.id === subject.id)
                                ? "text-black"
                                : "opacity-50"
                            }`}
                          >
                            <Check
                              className={`h-4 w-4 ${
                                selectedSubjects.some((s) => s.id === subject.id) ? "opacity-100" : "opacity-0"
                              }`}
                            />
                          </div>
                          {subject.name}
                        </CommandItem>
                      ))}
                    </CommandGroup>
                  </CommandList>
                </Command>
              </PopoverContent>
            </Popover>
            <div className="flex flex-wrap gap-1 mt-1">
              {selectedSubjects.map((subject) => (
                <Badge key={subject.id} variant="secondary" className="flex items-center gap-1">
                  {subject.name}
                  <X
                    className="h-3 w-3 cursor-pointer"
                    onClick={() => removeItem(subject, selectedSubjects, setSelectedSubjects, "subjects")}
                  />
                </Badge>
              ))}
            </div>
            {errors.subjects && <p className="text-xs text-red-400">{errors.subjects.message}</p>}
          </div>

          {/* Multi-Select for Classes with shadcn/ui */}
          <div className="flex flex-col gap-2 w-full md:w-1/4">
            <label className="text-xs text-gray-600">Classes</label>
            <Popover>
              <PopoverTrigger asChild>
                <Button
                  variant="outline"
                  className="w-full justify-between bg-white ring-1 ring-gray-300 p-2 rounded-md text-sm h-auto min-h-10"
                >
                  {selectedClasses.length > 0 ? `${selectedClasses.length} selected` : "Select classes"}
                  <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
              </PopoverTrigger>
              <PopoverContent className="w-full p-0" align="start">
                <Command>
                  <CommandInput placeholder="Search classes..." />
                  <CommandList>
                    <CommandEmpty>No classes found.</CommandEmpty>
                    <CommandGroup>
                      {classes?.map((cls) => (
                        <CommandItem
                          key={cls.id}
                          onSelect={() => toggleSelection(cls, selectedClasses, setSelectedClasses, "classes")}
                          className="flex items-center gap-2"
                        >
                          <div
                            className={`flex-shrink-0 rounded-full p-1 ${
                              selectedClasses.some((c) => c.id === cls.id)
                                ? " text-black"
                                : "opacity-50"
                            }`}
                          >
                            <Check
                              className={`h-2 w-2 ${
                                selectedClasses.some((c) => c.id === cls.id) ? "opacity-100 " : "opacity-0"
                              }`}
                            />
                          </div>
                          {cls.name}
                        </CommandItem>
                      ))}
                    </CommandGroup>
                  </CommandList>
                </Command>
              </PopoverContent>
            </Popover>
            <div className="flex flex-wrap gap-1 mt-1">
              {selectedClasses.map((cls) => (
                <Badge key={cls.id} variant="secondary" className="flex items-center gap-1">
                  {cls.name}
                  <X
                    className="h-3 w-3 cursor-pointer"
                    onClick={() => removeItem(cls, selectedClasses, setSelectedClasses, "classes")}
                  />
                </Badge>
              ))}
            </div>
            {errors.classes && <p className="text-xs text-red-400">{errors.classes.message}</p>}
          </div>

          {/* Multi-Select for Schools with shadcn/ui */}
          <div className="flex flex-col gap-2 w-full md:w-1/4">
            <label className="text-xs text-gray-600">Schools</label>
            <Popover>
              <PopoverTrigger asChild>
                <Button
                  variant="outline"
                  className="w-full justify-between bg-white ring-1 ring-gray-300 p-2 rounded-md text-sm h-auto min-h-10"
                >
                  {selectedSchools.length > 0 ? `${selectedSchools.length} selected` : "Select schools"}
                  <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
              </PopoverTrigger>
              <PopoverContent className="w-full p-0" align="start">
                <Command>
                  <CommandInput placeholder="Search schools..." />
                  <CommandList>
                    <CommandEmpty>No schools found.</CommandEmpty>
                    <CommandGroup>
                      {schools?.map((school) => (
                        <CommandItem
                          key={school.id}
                          onSelect={() => toggleSelection(school, selectedSchools, setSelectedSchools, "schools")}
                          className="flex items-center gap-2"
                        >
                          <div
                            className={`flex-shrink-0 rounded-full p-1 ${
                              selectedSchools.some((s) => s.id === school.id)
                                ? "text-black"
                                : "opacity-50"
                            }`}
                          >
                            <Check
                              className={`h-4 w-4 ${
                                selectedSchools.some((s) => s.id === school.id) ? "opacity-100" : "opacity-0"
                              }`}
                            />
                          </div>
                          {school.name}
                        </CommandItem>
                      ))}
                    </CommandGroup>
                  </CommandList>
                </Command>
              </PopoverContent>
            </Popover>
            <div className="flex flex-wrap gap-1 mt-1">
              {selectedSchools.map((school) => (
                <Badge key={school.id} variant="secondary" className="flex items-center gap-1">
                  {school.name}
                  <X
                    className="h-3 w-3 cursor-pointer"
                    onClick={() => removeItem(school, selectedSchools, setSelectedSchools, "schools")}
                  />
                </Badge>
              ))}
            </div>
            {errors.schools && <p className="text-xs text-red-400">{errors.schools.message}</p>}
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
  )
}

export default TeacherForm

