import React from 'react';
import { PieChart, Pie, Cell, ResponsiveContainer, Tooltip, Legend } from 'recharts';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';

const COLORS = ['#0088FE', '#00C49F', '#FFBB28', '#FF8042'];

const Performance = ({ student }) => {
    console.log('Full student data received:', student);
    console.log('Attendance data specifically:', student?.attendances);

    // Helper function to get current academic year period
    const getCurrentAcademicYear = () => {
        const now = new Date();
        const month = now.getMonth() + 1; // JavaScript months are 0-based
        const year = now.getFullYear();
        
        // If current month is before August, we're in the previous academic year
        if (month < 8) {
            return {
                start: new Date(year - 1, 7, 1),  // August 1st of previous year
                end: new Date(year, 7, 31)        // July 31st of current year
            };
        } else {
            return {
                start: new Date(year, 7, 1),      // August 1st of current year
                end: new Date(year + 1, 7, 31)    // July 31st of next year
            };
        }
    };

    // Helper function to check if a date is within current academic year
    const isInCurrentAcademicYear = (date) => {
        if (!date) return false;
        
        const academicYear = getCurrentAcademicYear();
        const checkDate = new Date(date);
        
        // Log date comparison
        console.log('Checking date:', {
            date: date,
            parsedDate: checkDate.toISOString(),
            academicYearStart: academicYear.start.toISOString(),
            academicYearEnd: academicYear.end.toISOString(),
            isAfterStart: checkDate >= academicYear.start,
            isBeforeEnd: checkDate <= academicYear.end
        });
        
        return checkDate >= academicYear.start && checkDate <= academicYear.end;
    };

    // Calculate academic score based on results
    const calculateAcademicScore = () => {
        console.log('=== ACADEMIC SCORE CALCULATION START ===');
        console.log('Full student object:', student);
        console.log('Results array:', student?.results);
        
        if (!student?.results) {
            console.log('No results property in student object');
            return 0;
        }

        if (!Array.isArray(student.results)) {
            console.log('Results is not an array:', typeof student.results);
            return 0;
        }

        if (student.results.length === 0) {
            console.log('Results array is empty');
            return 0;
        }

        // Log each result in detail
        student.results.forEach((result, index) => {
            console.log(`Result ${index + 1} details:`, {
                id: result.id,
                final_grade: result.final_grade,
                created_at: result.created_at,
                exam_date: result.exam_date,
                subject: result.subject,
                full_result: result
            });
        });

        // First, let's just check if we have any valid grades at all
        const resultsWithGrades = student.results.filter(result => {
            const hasGrade = result?.final_grade !== undefined && result?.final_grade !== null;
            console.log(`Result ${result.id} grade check:`, {
                grade: result.final_grade,
                hasGrade,
                type: typeof result.final_grade
            });
            return hasGrade;
        });

        console.log('Results with grades:', resultsWithGrades);

        if (resultsWithGrades.length === 0) {
            console.log('No results with valid grades found');
            return 0;
        }

        // Now calculate the average of all grades, regardless of date
        const validResults = resultsWithGrades.map(result => ({
            ...result,
            final_grade: parseFloat(result.final_grade) || 0
        }));

        console.log('Processed valid results:', validResults);

        const totalScore = validResults.reduce((sum, result) => sum + result.final_grade, 0);
        const averageScore = totalScore / validResults.length;
        const score = (averageScore / 20) * 10; // Convert to 0-10 scale

        console.log('Academic score calculation:', {
            totalResults: student.results.length,
            resultsWithGrades: resultsWithGrades.length,
            validResults: validResults.length,
            totalScore,
            averageScore,
            finalScore: score,
            gradeBreakdown: validResults.map(r => ({
                id: r.id,
                grade: r.final_grade,
                convertedScore: (r.final_grade / 20) * 10
            }))
        });

        console.log('=== ACADEMIC SCORE CALCULATION END ===');
        return score;
    };

    // Calculate attendance score
    const calculateAttendanceScore = () => {
        console.log('Starting attendance score calculation...');
        
        // Check if we have attendance data
        if (!student?.attendances || !Array.isArray(student.attendances)) {
            console.log('No valid attendance data found');
            return 10; // Return 10 if no attendance records (all days assumed present)
        }

        // Filter and normalize attendance records for current academic year only
        const validAttendances = student.attendances
            .filter(attendance => 
                attendance?.status && 
                typeof attendance.status === 'string' &&
                isInCurrentAcademicYear(attendance.date)
            )
            .map(attendance => ({
                ...attendance,
                status: attendance.status.toLowerCase(),
                date: new Date(attendance.date)
            }));

        console.log('Valid attendance records for current academic year:', validAttendances);
        console.log('Academic year period:', getCurrentAcademicYear());

        if (validAttendances.length === 0) {
            console.log('No attendance records found for current academic year, assuming all days present');
            return 10;
        }

        // Count attendance types
        const absentDays = validAttendances.filter(a => a.status === 'absent').length;
        const lateDays = validAttendances.filter(a => a.status === 'late').length;

        // Start with 10 and subtract points
        const deductions = (absentDays * 0.5) + (lateDays * 0.25);
        const score = Math.max(0, 10 - deductions);

        console.log('Attendance calculation for current academic year:', {
            absentDays,
            lateDays,
            deductions,
            score,
            academicYear: getCurrentAcademicYear()
        });

        return score;
    };

    // Calculate payment score based on memberships
    const calculatePaymentScore = () => {
        if (!student?.memberships || !Array.isArray(student.memberships)) {
            return 0;
        }
        
        // Filter memberships for current academic year only
        const validMemberships = student.memberships.filter(m => 
            m?.payment_status && 
            typeof m.payment_status === 'string' &&
            m?.created_at && 
            isInCurrentAcademicYear(m.created_at)
        );
        
        console.log('Valid memberships for current academic year:', validMemberships);
        
        if (validMemberships.length === 0) {
            console.log('No memberships found for current academic year');
            return 0;
        }
        
        const totalMemberships = validMemberships.length;
        const paidMemberships = validMemberships.filter(m => m.payment_status === 'paid').length;
        const paymentRate = (paidMemberships / totalMemberships) * 100;
        const score = (paymentRate / 100) * 10;

        console.log('Payment calculation for current academic year:', {
            totalMemberships,
            paidMemberships,
            paymentRate,
            score,
            academicYear: getCurrentAcademicYear()
        });

        return score;
    };

    // Calculate total score
    const calculateTotalScore = () => {
        const academicScore = calculateAcademicScore();
        const attendanceScore = calculateAttendanceScore();
        const paymentScore = calculatePaymentScore();

        console.log('Final scores:', {
            academicScore,
            attendanceScore,
            paymentScore
        });

        return (
            (academicScore * 0.4) +    // 40% weight
            (attendanceScore * 0.4) +   // 40% weight
            (paymentScore * 0.2)        // 20% weight
        );
    };

    const academicScore = calculateAcademicScore();
    const attendanceScore = calculateAttendanceScore();
    const paymentScore = calculatePaymentScore();
    const totalScore = calculateTotalScore();

    console.log('Final scores:', {
        academicScore,
        attendanceScore,
        paymentScore,
        totalScore
    });

    const renderScoreCard = (title, score, color) => (
        <Card className="w-full -mb-2">
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-bold" style={{ color }}>
                    {score.toFixed(1)}/10
                </div>
            </CardContent>
        </Card>
    );

    const data = [
        { name: 'Academic', value: academicScore },
        { name: 'Attendance', value: attendanceScore },
        { name: 'Payment', value: paymentScore }
    ];

    return (
        <div className="space-y-6 pb-10 ">
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                {renderScoreCard('Academic Score', academicScore, COLORS[0])}
                {renderScoreCard('Attendance Score', attendanceScore, COLORS[1])}
                {renderScoreCard('Payment Score', paymentScore, COLORS[2])}
            </div>

            <Card >
                <CardHeader className='-mb-12'>
                    <CardTitle>Performance Overview</CardTitle>
                </CardHeader>
                <CardContent className='pb-20'>
                    <div className="h-[300px] ">
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie
                                    data={data}
                                    cx="50%"
                                    cy="50%"
                                    innerRadius={60}
                                    outerRadius={80}
                                    paddingAngle={5}
                                    dataKey="value"
                                >
                                    {data.map((entry, index) => (
                                        <Cell key={`cell-${index}`} fill={COLORS[index % COLORS.length]} />
                                    ))}
                                </Pie>
                                <Tooltip 
                                    formatter={(value) => [`${value.toFixed(1)}/10`, 'Score']}
                                />
                                <Legend />
                            </PieChart>
                        </ResponsiveContainer>
                        <div className="text-center ">
                            <div className="text-3xl font-bold">{totalScore.toFixed(1)}</div>
                            <div className="text-sm text-gray-500">Total Score</div>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
};

export default Performance; 