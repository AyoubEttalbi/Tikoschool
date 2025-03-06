import { Clock, Edit, GraduationCap, Plus, Users } from "lucide-react"
import MembershipForm from "./forms/MembershipForm"
import FormModal from "./FormModal"

export default function MembershipCard({offers}) {
  

  return (
    <div className="w-full max-w-lg p-6 bg-white rounded-lg shadow-sm">
      <div className="flex justify-between items-center mb-6">
        <div className="flex items-center gap-2 text-blue-600 font-medium">
          <Users className="h-5 w-5" />
          <span>Memberships:</span>
        </div>
        <FormModal table="membership" type="create" data={offers} />
      </div>

      {offers.map((offer) => (
        <div key={offer.id} className="border border-gray-200 rounded-md mb-4">
          <div className="p-4">
            <div className="flex justify-between items-start">
              <div className="space-y-4">
                <div className="flex items-center gap-2 text-gray-700 font-medium">
                  <GraduationCap className="h-5 w-5 text-gray-600" />
                  <span>
                    Offer: <span className="text-gray-800">{offer.name}</span>
                  </span>
                </div>

                <div className="space-y-1">
                  <div className="flex items-center gap-2 text-gray-700 font-medium">
                    <Users className="h-5 w-5 text-gray-600" />
                    <span>Teachers:</span>
                  </div>

                  <div className="ml-7 space-y-2">
                    {offer.teachers.map((teacher) => (
                      <div key={teacher.subject} className="flex items-center gap-2 text-gray-600">
                        <Users className="h-4 w-4" />
                        <span>
                          {teacher.subject} : <span className="text-blue-600">{teacher.teacher}</span>
                        </span>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
              <FormModal table="membership" type="update" data={offers} id={offer.id} />
              
            </div>

            <div className="flex items-center gap-1 text-gray-500 text-sm mt-4 justify-end">
              <Clock className="h-4 w-4" />
              <span>{offer.date}</span>
            </div>
          </div>
        </div>
      ))}
    </div>
  )
}