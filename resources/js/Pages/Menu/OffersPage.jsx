import { useEffect, useState } from "react"
import FormModal from "@/Components/FormModal"
import TableSearch from "@/Components/TableSearch"
import DashboardLayout from "@/Layouts/DashboardLayout"
import { router, usePage } from "@inertiajs/react"
import Pagination from "../../Components/Pagination"
import { Filter, SortDesc } from "lucide-react"
import OfferCard from "@/Components/OfferCard"


export default function OffersPage({ offers = [], Alllevels = [], Allsubjects = [] }) {
  const role = usePage().props.auth.user.role

  const [editMode, setEditMode] = useState({})
  const [editData, setEditData] = useState({})
  const [dropdownStates, setDropdownStates] = useState({})

  // Initialize edit data for each offer
  // Sort offers by created_at descending (latest first)
  const safeOffers = Array.isArray(offers?.data) ? offers.data : [];
  const sortedOffers = [...safeOffers].sort((a, b) => new Date(b.created_at) - new Date(a.created_at));

  useEffect(() => {
    const initialEditData = {}
    sortedOffers.forEach((offer) => {
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
    setEditData((prev) => ({
      ...prev,
      [offerId]: {
        ...prev[offerId],
        percentage: {
          ...prev[offerId].percentage,
          [newSubject]: 0,
        },
      },
    }))
    toggleDropdown(offerId)
  }

  const handleRemoveSubject = (offerId, index) => {
    setEditData((prev) => {
      const updatedSubjects = [...prev[offerId].subjects]
      const removedSubject = updatedSubjects.splice(index, 1)[0]
      const updatedPercentage = { ...prev[offerId].percentage }
      delete updatedPercentage[removedSubject]
  
      return {
        ...prev,
        [offerId]: {
          ...prev[offerId],
          subjects: updatedSubjects,
          percentage: updatedPercentage,
        },
      }
    })
  }

  const getSubjectName = (subject) => {
    if (subject && typeof subject === "object" && subject.name) {
      return subject.name
    }
    return subject
  }

  const handleSaveChanges = (offerId) => {
    console.log("Saving changes for offer ID:", offerId, editData[offerId])
    toggleEditMode(offerId)
    router.put(`/offers/${offerId}`, editData[offerId])
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
            }
            return (
              <OfferCard
                key={offer.id}
                offer={offer}
                isEditMode={editMode[offer.id]}
                toggleEditMode={() => toggleEditMode(offer.id)}
                currentEditData={editData[offer.id] || { subjects: [] }}
                handleInputChange={handleInputChange}
                handleSaveChanges={handleSaveChanges}
                toggleDropdown={() => toggleDropdown(offer.id)}
                dropdownStates={dropdownStates}
                handleAddSubject={handleAddSubject}
                handleRemoveSubject={handleRemoveSubject}
                handlePercentageChange={handlePercentageChange}
                incrementPercentage={incrementPercentage}
                decrementPercentage={decrementPercentage}
                availableSubjects={Allsubjects}
                getSubjectName={getSubjectName}
                levels={Alllevels}
                subjects={Allsubjects}
              />
            )
          })}
        </div>
      </div>
      <Pagination links={offers.links} />
    </div>
  )
}

OffersPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>