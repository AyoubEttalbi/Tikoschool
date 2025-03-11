
import { useEffect, useState } from "react"
import FormModal from "@/Components/FormModal"
import TableSearch from "@/Components/TableSearch"
import DashboardLayout from "@/Layouts/DashboardLayout"
import { usePage } from "@inertiajs/react"
import Pagination from "../../Components/Pagination"
import {
  Filter,
  SortDesc,
  Plus,
  Edit2,
  Trash2,
  Book,
  DollarSign,
  Percent,
  X,
  ChevronDown,
  ChevronUp,
  Check,
  Pencil,
  MinusCircle,
  PlusCircle,
} from "lucide-react"

export default function OffersPage({ offers = [], Alllevels = [], Allsubjects = [] }) {
  const role = usePage().props.auth.user.role

  const [editMode, setEditMode] = useState({})
  const [editData, setEditData] = useState({})
  const [dropdownStates, setDropdownStates] = useState({})

  // Initialize edit data for each offer
  useEffect(() => {
    const initialEditData = {}
    offers.data.forEach((offer) => {
      initialEditData[offer.id] = {
        offer_name: offer.offer_name,
        price: offer.price,
        subjects: [...offer.subjects],
        percentage: { ...offer.percentage },
      }
    })
    setEditData(initialEditData)
  }, [offers])

  const toggleEditMode = (offerId) => {
    setEditMode((prev) => ({
      ...prev,
      [offerId]: !prev[offerId],
    }))
  }

  const toggleDropdown = (offerId) => {
    setDropdownStates((prev) => ({
      ...prev,
      [offerId]: !prev[offerId],
    }))
  }

  const handleInputChange = (offerId, field, value) => {
    setEditData((prev) => ({
      ...prev,
      [offerId]: {
        ...prev[offerId],
        [field]: value,
      },
    }))
  }

  const handlePercentageChange = (offerId, subject, value) => {
    setEditData((prev) => ({
      ...prev,
      [offerId]: {
        ...prev[offerId],
        percentage: {
          ...prev[offerId].percentage,
          [subject]: value,
        },
      },
    }))
  }

  const incrementPercentage = (offerId, subject) => {
    setEditData((prev) => {
      const currentValue = Number.parseInt(prev[offerId].percentage[subject]) || 0
      return {
        ...prev,
        [offerId]: {
          ...prev[offerId],
          percentage: {
            ...prev[offerId].percentage,
            [subject]: Math.min(100, currentValue + 1),
          },
        },
      }
    })
  }

  const decrementPercentage = (offerId, subject) => {
    setEditData((prev) => {
      const currentValue = Number.parseInt(prev[offerId].percentage[subject]) || 0
      return {
        ...prev,
        [offerId]: {
          ...prev[offerId],
          percentage: {
            ...prev[offerId].percentage,
            [subject]: Math.max(0, currentValue - 1),
          },
        },
      }
    })
  }

  const handleAddSubject = (offerId, newSubject) => {
    if (!newSubject) return

    setEditData((prev) => ({
      ...prev,
      [offerId]: {
        ...prev[offerId],
        subjects: [...prev[offerId].subjects, newSubject],
      },
    }))

    toggleDropdown(offerId)
  }

  const handleRemoveSubject = (offerId, index) => {
    setEditData((prev) => {
      const updatedSubjects = [...prev[offerId].subjects]
      updatedSubjects.splice(index, 1)

      return {
        ...prev,
        [offerId]: {
          ...prev[offerId],
          subjects: updatedSubjects,
        },
      }
    })
  }

  // Helper function to get subject name
  const getSubjectName = (subject) => {
    // If subject is an object with a name property, return that
    if (subject && typeof subject === "object" && subject.name) {
      return subject.name
    }
    // Otherwise return the subject itself (assuming it's a string)
    return subject
  }

  const handleSaveChanges = (offerId) => {
    // Here you would typically save the changes to your backend
    console.log("Saving changes for offer ID:", offerId, editData[offerId])
    toggleEditMode(offerId)
  }

  return (
    <div className="p-6 bg-gray-50 min-h-screen">
      <div className="mx-auto ">
        <div className="flex items-center justify-between mb-8">
          <h1 className="hidden md:block text-2xl font-semibold">All Offers</h1>
          <div className="flex flex-col md:flex-row items-center gap-4 w-full md:w-auto">
            <TableSearch routeName="offers.index" />
            <div className="flex items-center gap-4 self-end">
              <button className="w-10 h-10 flex items-center justify-center rounded-full bg-lamaYellowLight hover:bg-lamaYellow transition-all duration-200 text-gray-700">
                <Filter className="w-5 h-5" />
              </button>
              <button className="w-10 h-10 flex items-center justify-center rounded-full bg-lamaYellowLight hover:bg-lamaYellow transition-all duration-200 text-gray-700">
                <SortDesc className="w-5 h-5" />
              </button>
              {role === "admin" && <FormModal table="offer" type="create" levels={Alllevels} subjects={Allsubjects} />}
            </div>
          </div>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 lg:gap-6 mt-4 gap-4">
          {offers.data.map((offer) => {
            const isEditMode = editMode[offer.id]
            const currentEditData = editData[offer.id] || {
              offer_name: offer.offer_name,
              price: offer.price,
              subjects: offer.subjects,
              percentage: offer.percentage,
            }

            const availableSubjects = Allsubjects.filter(
              (s) =>
                !currentEditData.subjects.some((subject) =>
                  typeof subject === "object" && typeof s === "object" ? subject.id === s.id : subject === s,
                ),
            )

            return (
              <div
                key={offer.id}
                className={`relative p-8 rounded-xl bg-white border border-gray-100 shadow-sm hover:shadow-xl transition-all duration-300 group overflow-hidden ${isEditMode ? "ring-2 ring-lamaPurple ring-opacity-50" : ""}`}
              >
                {/* Decorative elements - hide when editing */}
                

                {/* Action Buttons */}
                <div className="absolute top-4 right-4 flex gap-2 opacity-0 group-hover:opacity-100 transition-all duration-200 transform group-hover:translate-y-0 translate-y-2">
                  {isEditMode ? (
                    <>
                      <button
                        onClick={() => handleSaveChanges(offer.id)}
                        className="p-2 bg-lamaPurpleLight rounded-full shadow-md hover:bg-lamaPurple transition-colors"
                      >
                        <Check className="w-4 h-4 text-gray-700" />
                      </button>
                      <button
                        onClick={() => toggleEditMode(offer.id)}
                        className="p-2 bg-red-50 rounded-full shadow-md hover:bg-red-100 transition-colors"
                      >
                        <X className="w-4 h-4 text-red-500" />
                      </button>
                    </>
                  ) : (
                    <>
                      <button
                        onClick={() => toggleEditMode(offer.id)}
                        className="p-2 bg-white rounded-full shadow-md hover:bg-lamaPurpleLight transition-colors"
                      >
                        <Edit2 className="w-4 h-4 text-gray-700" />
                      </button>
                      <button className="p-2 bg-white rounded-full shadow-md hover:bg-red-50 transition-colors">
                        <Trash2 className="w-4 h-4 text-red-500" />
                      </button>
                    </>
                  )}
                </div>

                {/* Price Tag */}
                <div
                  className={`absolute -top-1 -left-1 bg-lamaYellow px-4 py-1.5 rounded-br-xl rounded-tl-md text-gray-800 flex items-center gap-1 shadow-md ${isEditMode ? "bg-opacity-90" : ""}`}
                >
                  <DollarSign className="w-4 h-4" />
                  {isEditMode ? (
                    <div className="flex items-center">
                      <input
                        type="text"
                        value={currentEditData.price}
                        onChange={(e) => handleInputChange(offer.id, "price", e.target.value)}
                        className="w-16 bg-transparent border-none focus:outline-none focus:ring-0 text-gray-800 font-bold p-0"
                      />
                      <div className="flex flex-col ml-1">
                        <button
                          onClick={() =>
                            handleInputChange(offer.id, "price", Number.parseFloat(currentEditData.price) + 1)
                          }
                          className="text-gray-700 hover:text-gray-900"
                        >
                          <ChevronUp className="w-3 h-3" />
                        </button>
                        <button
                          onClick={() =>
                            handleInputChange(
                              offer.id,
                              "price",
                              Math.max(0, Number.parseFloat(currentEditData.price) - 1),
                            )
                          }
                          className="text-gray-700 hover:text-gray-900"
                        >
                          <ChevronDown className="w-3 h-3" />
                        </button>
                      </div>
                    </div>
                  ) : (
                    <span className="font-bold">{offer.price}</span>
                  )}
                </div>

                {/* Offer Header */}
                <div className="mt-8 mb-6 relative z-10">
                  {isEditMode ? (
                    <div className="relative group/edit">
                      <div className="flex items-center">
                        <input
                          type="text"
                          value={currentEditData.offer_name}
                          onChange={(e) => handleInputChange(offer.id, "offer_name", e.target.value)}
                          className="text-2xl font-bold text-gray-800 bg-transparent focus:outline-none focus:ring-0 w-full pb-1 border-b border rounded-lg border-gray-300 focus:border-lamaPurple "
                        />
                        <Pencil className="ml-2 w-4 h-4 text-black opacity-70" />
                      </div>
                    </div>
                  ) : (
                    <h3 className="text-2xl font-bold text-gray-800">{offer.offer_name}</h3>
                  )}
                </div>

                {/* Subjects */}
                <div className="mb-6 relative z-10">
                  <div className="flex items-center gap-2 text-sm text-gray-600 mb-4">
                    <Book className="w-5 h-5 text-lamaPurple" />
                    <p className="font-medium text-base">Subjects</p>
                    {isEditMode && (
                      <button
                        onClick={() => toggleDropdown(offer.id)}
                        className="p-1 rounded-full hover:bg-lamaPurpleLight transition-colors"
                      >
                        <Plus className="w-4 h-4 text-gray-700" />
                      </button>
                    )}
                  </div>

                  {isEditMode && dropdownStates[offer.id] && (
                    <div className="relative mb-3">
                      <div className="relative">
                        <select
                          onChange={(e) => handleAddSubject(offer.id, e.target.value)}
                          className="w-full px-4 py-2 bg-white rounded-lg text-sm font-medium text-gray-700 appearance-none focus:outline-none focus:ring-1 focus:ring-lamaPurple border border-gray-200"
                        >
                          <option value="">Select subject</option>
                          {availableSubjects.map((subj) => (
                            <option
                              key={typeof subj === "object" ? subj.id : subj}
                              value={typeof subj === "object" ? subj.id : subj}
                            >
                              {typeof subj === "object" ? subj.name : subj}
                            </option>
                          ))}
                        </select>
                        <ChevronDown className="absolute right-3 top-1/2 transform -translate-y-1/2 w-4 h-4 text-gray-500 pointer-events-none" />
                      </div>
                    </div>
                  )}

                  <div className="flex flex-wrap gap-2">
                    {currentEditData.subjects?.map((subject, idx) => (
                      <span
                        key={idx}
                        className="px-4 py-1.5 bg-lamaPurpleLight rounded-full text-sm font-medium text-gray-700 flex items-center gap-1"
                      >
                        {getSubjectName(subject)}
                        {isEditMode && (
                          <button
                            onClick={() => handleRemoveSubject(offer.id, idx)}
                            className="ml-1 text-gray-500 hover:text-red-500 transition-colors"
                          >
                            <X className="w-3 h-3" />
                          </button>
                        )}
                      </span>
                    ))}
                  </div>
                </div>

                {/* Percentage Breakdown */}
                <div className="space-y-3 relative z-10">
                  <div className="flex items-center gap-2 text-sm text-gray-600 mb-4">
                    <Percent className="w-5 h-5 text-lamaPurple" />
                    <p className="font-medium text-base">Breakdown</p>
                  </div>

                  {Object.entries(isEditMode ? currentEditData.percentage : offer.percentage).map(
                    ([subject, percent], index) => (
                      <div
                        key={subject}
                        className="flex justify-between items-center text-gray-700 py-2.5 px-4 rounded-lg bg-lamaPurpleLight"
                      >
                        <span className="text-base font-medium">{getSubjectName(subject)}</span>
                        {isEditMode ? (
                          <div className="flex items-center gap-1">
                            <button
                              onClick={() => decrementPercentage(offer.id, subject)}
                              className="text-gray-500 hover:text-gray-700 transition-colors"
                            >
                              <MinusCircle className="w-4 h-4" />
                            </button>
                            <input
                              type="text"
                              value={percent}
                              onChange={(e) => handlePercentageChange(offer.id, subject, e.target.value)}
                              className="w-12 bg-transparent border-none focus:outline-none focus:ring-0 text-right text-base font-bold text-gray-800"
                            />
                            <span className="text-base font-bold text-gray-800">%</span>
                            <button
                              onClick={() => incrementPercentage(offer.id, subject)}
                              className="text-gray-500 hover:text-gray-700 transition-colors"
                            >
                              <PlusCircle className="w-4 h-4" />
                            </button>
                          </div>
                        ) : (
                          <span className="text-base font-bold text-gray-800">{percent}%</span>
                        )}
                      </div>
                    ),
                  )}
                </div>
              </div>
            )
          })}
        </div>
      </div>
      <Pagination links={offers.links} />
    </div>
  )
}

OffersPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>