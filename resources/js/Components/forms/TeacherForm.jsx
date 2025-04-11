import { zodResolver } from "@hookform/resolvers/zod"
import { useForm } from "react-hook-form"
import { z } from "zod"
import { useState } from "react"
import InputField from "../InputField"
import { router, Link } from "@inertiajs/react"
import { Check, ChevronsUpDown, X, Upload, ChevronUp, ChevronDown } from "lucide-react"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/Components/ui/select"
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from "@/Components/ui/command"
import { Popover, PopoverContent, PopoverTrigger } from "@/Components/ui/popover"
import { Badge } from "@/Components/ui/badge"
import { Button } from "@/Components/ui/button"
import Register from "@/Pages/Auth/Register";

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
  profile_image: z.any().optional(),
})

const TeacherForm = ({ type, data, subjects, classes, schools, setOpen }) => {
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [selectedStatus, setSelectedStatus] = useState(data?.status || "active")
  const [selectedSubjects, setSelectedSubjects] = useState(data?.subjects || [])
  const [selectedClasses, setSelectedClasses] = useState(data?.classes?.map(({ id, name }) => ({ id, name })) || [])
  const [selectedSchools, setSelectedSchools] = useState(data?.schools?.map(({ id, name }) => ({ id, name })) || [])
  const [imagePreview, setImagePreview] = useState(data?.profile_image || null)
  const [loading, setLoading] = useState(false)

  const {
    register,
    handleSubmit,
    control,
    setValue,
    watch,
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
      profile_image: null,
    },
  })

  // Watch the form fields for changes
  const firstName = watch("firstName");
  const lastName = watch("lastName");
  const email = watch("email");

  // Prepare user data for the Register component
  const userData = {
    name: `${firstName} ${lastName}`,
    email: email,
  };

  // Handle image change
  const handleImageChange = (e) => {
    const file = e.target.files[0];
    if (file) {
      setValue('profile_image', file);
      // Create a preview URL
      const reader = new FileReader();
      reader.onload = (e) => {
        setImagePreview(e.target.result);
      };
      reader.readAsDataURL(file);
    }
  };

  const onSubmit = handleSubmit((formData) => {
    // Create a FormData object to properly handle file uploads
    const formDataObj = new FormData();
    
    // Append all form fields
    formDataObj.append('first_name', formData.firstName);
    formDataObj.append('last_name', formData.lastName);
    formDataObj.append('address', formData.address || '');
    formDataObj.append('phone_number', formData.phoneNumber || '');
    formDataObj.append('email', formData.email);
    formDataObj.append('status', formData.status);
    formDataObj.append('wallet', formData.wallet);
    
    // Append the file if it exists
    if (formData.profile_image) {
      formDataObj.append('profile_image', formData.profile_image);
    }
    
    // Append each subject ID
    formData.subjects?.forEach((subject, index) => {
      formDataObj.append(`subjects[${index}]`, subject.id);
    });
    
    // Append each class ID
    formData.classes?.forEach((cls, index) => {
      formDataObj.append(`classes[${index}]`, cls.id);
    });
    
    // Append each school ID
    formData.schools?.forEach((school, index) => {
      formDataObj.append(`schools[${index}]`, school.id);
    });

    setLoading(true);

    if (type === "create") {
      router.post("/teachers", formDataObj, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
          setOpen(false);
          setLoading(false);
        },
        onError: (errors) => {
          console.log("Inertia Errors:", errors);
          setLoading(false);
        },
      });
    } else if (type === "update") {
      // For update, we need to use method spoofing with Inertia
      formDataObj.append('_method', 'PUT');
      
      // Add flag to indicate this is a form update
      formDataObj.append('is_form_update', '1');
      
      router.post(`/teachers/${data.id}`, formDataObj, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
          setOpen(false);
          setLoading(false);
        },
        onError: (errors) => {
          console.log("Inertia Errors:", errors);
          setLoading(false);
        },
      });
    }
  });

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
      <form className="flex flex-col gap-8" onSubmit={onSubmit} encType="multipart/form-data">
        <h1 className="text-xl font-semibold">{type === "create" ? "Create a New Teacher" : "Update Teacher"}</h1>

        {/* Personal Information */}
        <div className="grid grid-cols-1 lg:grid-cols-3 md:grid-cols-2 gap-4">
          <InputField label="First Name" name="firstName" register={register} error={errors.firstName} />
          <InputField label="Last Name" name="lastName" register={register} error={errors.lastName} />
          <InputField label="Address" name="address" register={register} error={errors.address} />
          <InputField label="Phone Number" name="phoneNumber" register={register} error={errors.phoneNumber} />
          <InputField label="Email" name="email" register={register} error={errors.email} />
          <InputField label="Wallet Amount" name="wallet" type="number" register={register} error={errors.wallet} />

          {/* Profile Image Upload */}
          <div className="flex flex-col gap-2 w-full">
            <label className="text-xs text-gray-500">Profile Image</label>
            <div className="flex flex-col gap-2">
              {imagePreview && (
                <div className="relative w-24 h-24 mb-2">
                  <img 
                    src={imagePreview} 
                    alt="Profile preview" 
                    className="w-full h-full object-cover rounded-md"
                  />
                </div>
              )}
              <label 
                htmlFor="profile_image" 
                className="flex items-center gap-2 cursor-pointer p-2 border border-gray-300 rounded-md hover:bg-gray-50 transition-colors duration-200 w-full"
              >
                <Upload className="w-4 h-4 text-gray-500" />
                <span className="text-sm text-gray-700">
                  {imagePreview ? "Change image" : "Upload image"}
                </span>
                <input
                  id="profile_image"
                  type="file"
                  accept="image/png, image/jpeg, image/jpg"
                  className="hidden"
                  onChange={handleImageChange}
                />
              </label>
              {errors.profile_image && (
                <p className="text-xs text-red-400">{errors.profile_image.message}</p>
              )}
            </div>
          </div>
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
          <button
            type="button"
            onClick={() => setIsModalOpen(!isModalOpen)}
            className="items-center mt-7 h-10 inline-flex gap-2 bg-blue-500 hover:bg-blue-600 transition-all text-white px-4 py-2 rounded-md shadow-sm"
          >
            {isModalOpen ? (
              <ChevronUp className="w-4 h-4" />
            ) : (
              <ChevronDown className="w-4 h-4" />
            )}
            <span>Add User</span>
          </button>
        </div>

        <button 
          type="submit" 
          disabled={loading}
          className={`bg-blue-500 hover:bg-blue-600 transition-all text-white p-2 rounded-md flex items-center justify-center ${
            loading ? "opacity-70 cursor-not-allowed" : ""
          }`}
        >
          {loading ? (
            <span className="flex items-center gap-2">
              <svg className="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              Processing...
            </span>
          ) : (
            <span>{type === "create" ? "Create" : "Update"}</span>
          )}
        </button>
       
      </form>
      {isModalOpen && (
          <Register
            setIsModalOpen={setIsModalOpen}
            table="teachers"
            role={"teacher"}
            isModalOpen={isModalOpen}
            UserData={userData}
          />
        )}
    </div>
  )
}

export default TeacherForm