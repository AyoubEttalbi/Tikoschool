import DashboardLayout from '@/Layouts/DashboardLayout';
import React, { useState } from 'react';
import FormModal from '../../Components/FormModal'; // Importing FormModal here
import TableSearch from '@/Components/TableSearch';

export default function OffersPage() {
  const [offers, setOffers] = useState([
    {
      id: 1,
      offer_name: 'Math & PC Package',
      subjects: ['Math', 'PC'],
      price: 100.00,
      percentage: { Math: '20%', PC: '30%' },
    },
    {
      id: 2,
      offer_name: 'Science & Arts Package',
      subjects: ['Science', 'Arts'],
      price: 120.00,
      percentage: { Science: '15%', Arts: '25%' },
    },
  ]);

  return (
    <div className="p-6">
      <div className="flex items-center justify-between">
        <h1 className="hidden md:block text-lg font-semibold">All Offers</h1>
        <div className="flex flex-col md:flex-row items-center gap-4 w-full md:w-auto">
         
          <div className="flex items-center gap-4 self-end">
          <TableSearch />
            <button className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow">
              <img src="/filter.png" alt="" width={14} height={14} />
            </button>
            <button className="w-8 h-8 flex items-center justify-center rounded-full bg-lamaYellow">
              <img src="/sort.png" alt="" width={14} height={14} />
            </button>
           
            <FormModal table="offer" type="create" />
            
          </div>
        </div>
      </div>

      
      <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6 mt-10">
  {offers.map((offer) => (
    <div key={offer.id} className="p-6 border rounded-lg shadow-lg hover:shadow-xl transition-shadow duration-300 bg-white">
      <h3 className="font-semibold text-xl text-gray-800 mb-2">{offer.offer_name}</h3>
      <p className="text-sm text-gray-600 mb-2">
        <span className="font-medium">Subjects:</span> {offer.subjects.join(', ')}
      </p>
      <p className="text-sm text-gray-600 mb-2">
        <span className="font-medium">Price:</span> ${offer.price}
      </p>
      <p className="text-sm text-gray-600">
        <span className="font-medium">Percentage:</span> 
        {Object.entries(offer.percentage).map(([subject, percent]) => (
          <span key={subject} className="inline-block mr-2">
            {subject}: {percent}
          </span>
        ))}
      </p>
    </div>
  ))}
</div>

    </div>
  );
}

OffersPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
